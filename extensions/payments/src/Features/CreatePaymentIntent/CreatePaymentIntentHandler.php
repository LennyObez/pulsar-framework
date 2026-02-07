<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CreatePaymentIntent;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\IdempotencyStoreInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Idempotency\IdempotencyClaimStatus;
use Pulsar\Extension\Payments\Internal\Support\ParametersHasher;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Throwable;

use function strlen;

/**
 * Create payment intent use case handler.
 *
 * Orchestrates provider call with idempotency, audit logging, and metrics.
 */
final readonly class CreatePaymentIntentHandler
{
    private const string IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,256}$/';

    public function __construct(
        private PaymentProviderInterface $provider,
        private IdempotencyStoreInterface $idempotencyStore,
        private AuditLogger $auditLogger,
        private MetricRegistry $metricRegistry,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private PaymentsConfig $config,
    ) {}

    /**
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function execute(CreatePaymentIntentRequest $request): CreatePaymentIntentResult
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        $parametersHash = ParametersHasher::hash('createIntent', [
            'amount_minor' => $request->amount->amount,
            'currency' => $request->amount->currency->value,
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $request->idempotencyKey,
            $parametersHash,
            'createIntent',
            $this->clock->now(),
            $this->config->idempotency->ttlSeconds,
        );

        if ($claim->status === IdempotencyClaimStatus::Mismatch) {
            throw IdempotencyException::parameterMismatch($request->idempotencyKey);
        }

        if ($claim->status === IdempotencyClaimStatus::Replay) {
            $this->incrementReplayMetric();

            /** @var string $payload */
            $payload = $claim->resultPayload;

            return new CreatePaymentIntentResult(
                intent: $this->deserializeIntent($payload),
                replayed: true,
            );
        }

        try {
            $intent = $this->provider->createIntent(
                $request->amount,
                $request->idempotencyKey,
                $request->metadata,
            );

            $this->commitResult($request->idempotencyKey, $intent);
            $this->auditPayment($intent->id, $request->amount, $intent->status->value);
            $this->incrementIntentMetric($request->amount, $intent->status->value);

            return new CreatePaymentIntentResult(intent: $intent);
        } catch (Throwable $e) {
            $this->idempotencyStore->release($request->idempotencyKey);
            $this->incrementProviderErrorMetric($e);

            throw $e;
        }
    }

    /**
     * @throws IdempotencyException
     */
    private function validateIdempotencyKey(string $key): void
    {
        if ($key === '' || strlen($key) > $this->config->idempotency->maxKeyLength) {
            throw IdempotencyException::invalidKey(
                'must be 1-' . $this->config->idempotency->maxKeyLength . ' ASCII printable characters',
            );
        }

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw IdempotencyException::invalidKey('contains invalid characters (only ASCII 0x21-0x7E allowed)');
        }
    }

    private function commitResult(string $key, PaymentIntent $intent): void
    {
        $payload = json_encode([
            'schema_version' => 1,
            'type' => 'payment_intent',
            'data' => [
                'id' => $intent->id,
                'amount' => $intent->amount->amount,
                'currency' => $intent->amount->currency->value,
                'status' => $intent->status->value,
                'provider' => $intent->provider,
                'idempotency_key' => $intent->idempotencyKey,
                'created_at' => $intent->createdAt->getTimestamp(),
                'metadata' => $intent->metadata,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $this->idempotencyStore->commit($key, $payload);
        } catch (Throwable $e) {
            $this->logger->critical('Failed to commit idempotency result', [
                'key' => $key,
                'type' => 'payment_intent',
                'error' => $e->getMessage(),
            ]);

            throw IdempotencyException::commitFailed($key);
        }
    }

    private function deserializeIntent(string $payload): PaymentIntent
    {
        /** @var array{data: array<string, mixed>} $envelope */
        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $data = $envelope['data'];

        return new PaymentIntent(
            id: (string) $data['id'],
            amount: Money::of((int) $data['amount'], Currency::from((string) $data['currency'])),
            status: PaymentIntentStatus::from((string) $data['status']),
            provider: (string) $data['provider'],
            idempotencyKey: (string) $data['idempotency_key'],
            createdAt: new DateTimeImmutable('@' . (int) $data['created_at']),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    private function auditPayment(string $intentId, Money $amount, string $status): void
    {
        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'create_intent',
            resource: 'payment_intent:' . $intentId,
            metadata: [
                'provider' => $this->provider->name(),
                'currency' => $amount->currency->value,
                'amount_minor_units' => $amount->amount,
                'status' => $status,
            ],
        );
    }

    private function incrementIntentMetric(Money $amount, string $status): void
    {
        $this->metricRegistry->counter(
            'payments_intents_total',
            'Total payment intents',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'currency' => $amount->currency->value,
            'status' => $status,
        ]));
    }

    private function incrementProviderErrorMetric(Throwable $e): void
    {
        $errorType = $e instanceof PaymentProviderException ? $e->errorType : 'unknown';

        $this->metricRegistry->counter(
            'payments_provider_errors_total',
            'Total payment provider errors',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'operation' => 'createIntent',
            'error_type' => $errorType,
        ]));
    }

    private function incrementReplayMetric(): void
    {
        $this->metricRegistry->counter(
            'payments_idempotency_replays_total',
            'Total idempotency replays',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'operation' => 'createIntent',
        ]));
    }
}
