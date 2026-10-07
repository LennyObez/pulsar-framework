<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CancelPaymentIntent;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Internal\Support\ParametersHasher;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Idempotency\IdempotencyClaimStatus;
use Pulsar\Idempotency\IdempotencyStoreInterface;
use Pulsar\Idempotency\SignedIdempotencyEnvelope;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\TenantContext;
use SodiumException;
use Throwable;

use function strlen;

/**
 * Cancel-payment-intent slice handler.
 *
 * Mirrors the {@see CreatePaymentIntentHandler} structure
 * (Handler + Request + Result).
 */
final readonly class CancelPaymentIntentHandler
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
        private SignedIdempotencyEnvelope $envelope,
        // Optional tenant stamping.
        private ?TenantContext $tenantContext = null,
    ) {}

    /**
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function execute(CancelPaymentIntentRequest $request): CancelPaymentIntentResult
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        $parametersHash = ParametersHasher::hash('cancelIntent', [
            'intentId' => $request->intentId,
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $request->idempotencyKey,
            $parametersHash,
            'cancelIntent',
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

            return new CancelPaymentIntentResult(
                intent: $this->deserializeIntent($request->idempotencyKey, $payload),
                replayed: true,
            );
        }

        try {
            $intent = $this->provider->cancelIntent($request->intentId, $request->idempotencyKey);

            $this->commitResult($request->idempotencyKey, $intent);
            $this->auditCancel($intent);
            $this->incrementIntentMetric($intent);

            return new CancelPaymentIntentResult(intent: $intent);
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
        try {
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

            $sealed = $this->envelope->seal($key, $payload);
        } catch (JsonException | SodiumException $e) {
            throw IdempotencyException::serializationFailed($key, $e);
        }

        try {
            $this->idempotencyStore->commit($key, $sealed);
        } catch (Throwable $e) {
            $this->logger->critical('Failed to commit idempotency result', [
                'key' => $key,
                'type' => 'payment_intent',
                'error' => $e->getMessage(),
            ]);

            throw IdempotencyException::commitFailed($key);
        }
    }

    private function deserializeIntent(string $idempotencyKey, string $sealed): PaymentIntent
    {
        try {
            $payload = $this->envelope->open($idempotencyKey, $sealed);

            /** @var array{data: array{id: string, amount: int, currency: string, status: string, provider: string, idempotency_key: string, created_at: int, metadata?: array<string, mixed>}} $envelope */
            $envelope = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException | SodiumException $e) {
            throw IdempotencyException::serializationFailed($idempotencyKey, $e);
        }

        $data = $envelope['data'];

        /** @var array<string, mixed> $metadata */
        $metadata = $data['metadata'] ?? [];

        return new PaymentIntent(
            id: $data['id'],
            amount: Money::of($data['amount'], Currency::from($data['currency'])),
            status: PaymentIntentStatus::from($data['status']),
            provider: $data['provider'],
            idempotencyKey: $data['idempotency_key'],
            createdAt: new DateTimeImmutable('@' . $data['created_at']),
            metadata: $metadata,
        );
    }

    private function auditCancel(PaymentIntent $intent): void
    {
        $metadata = [
            'provider' => $this->provider->name(),
            'currency' => $intent->amount->currency->value,
            'amount_minor_units' => $intent->amount->amount,
            'status' => $intent->status->value,
        ];

        $tenant = $this->tenantContext?->tryGet();
        if ($tenant !== null) {
            $metadata['tenant_id'] = $tenant->id;
        }

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'cancel_intent',
            resource: 'payment_intent:' . $intent->id,
            metadata: $metadata,
        );
    }

    private function incrementIntentMetric(PaymentIntent $intent): void
    {
        $this->metricRegistry->counter(
            'payments_intents_total',
            'Total payment intents',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'currency' => $intent->amount->currency->value,
            'status' => $intent->status->value,
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
            'operation' => 'cancelIntent',
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
            'operation' => 'cancelIntent',
        ]));
    }
}
