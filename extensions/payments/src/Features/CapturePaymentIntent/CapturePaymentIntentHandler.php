<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\CapturePaymentIntent;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
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
 * Capture-payment-intent slice handler.
 *
 * Mirrors the {@see CreatePaymentIntentHandler} structure
 * (Handler + Request + Result): capture, cancel, refund and create
 * are four self-contained vertical slices, each with the same
 * idempotency claim → provider call → commit → audit → metrics
 * orchestration.
 */
final readonly class CapturePaymentIntentHandler
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
        // Optional tenant stamping. Single-tenant deployments
        // leave it null; multi-tenant ones inject the active context.
        private ?TenantContext $tenantContext = null,
    ) {}

    /**
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function execute(CapturePaymentIntentRequest $request): CapturePaymentIntentResult
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        $parametersHash = ParametersHasher::hash('captureIntent', [
            'intentId' => $request->intentId,
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $request->idempotencyKey,
            $parametersHash,
            'captureIntent',
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

            return new CapturePaymentIntentResult(
                charge: $this->deserializeCharge($request->idempotencyKey, $payload),
                replayed: true,
            );
        }

        try {
            $charge = $this->provider->captureIntent($request->intentId, $request->idempotencyKey);

            $this->commitResult($request->idempotencyKey, $charge);
            $this->auditCapture($charge);
            $this->incrementCaptureMetric($charge);

            return new CapturePaymentIntentResult(charge: $charge);
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

    private function commitResult(string $key, Charge $charge): void
    {
        try {
            $payload = json_encode([
                'schema_version' => 1,
                'type' => 'charge',
                'data' => [
                    'id' => $charge->id,
                    'intent_id' => $charge->intentId,
                    'amount' => $charge->amount->amount,
                    'currency' => $charge->amount->currency->value,
                    'status' => $charge->status->value,
                    'provider' => $charge->provider,
                    'created_at' => $charge->createdAt->getTimestamp(),
                    'failure_reason' => $charge->failureReason,
                    'metadata' => $charge->metadata,
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
                'type' => 'charge',
                'error' => $e->getMessage(),
            ]);

            throw IdempotencyException::commitFailed($key);
        }
    }

    private function deserializeCharge(string $idempotencyKey, string $sealed): Charge
    {
        try {
            $payload = $this->envelope->open($idempotencyKey, $sealed);

            /** @var array{data: array{id: string, intent_id: string, amount: int, currency: string, status: string, provider: string, created_at: int, failure_reason: string|null, metadata?: array<string, mixed>}} $envelope */
            $envelope = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException | SodiumException $e) {
            throw IdempotencyException::serializationFailed($idempotencyKey, $e);
        }

        $data = $envelope['data'];

        /** @var array<string, mixed> $metadata */
        $metadata = $data['metadata'] ?? [];

        return new Charge(
            id: $data['id'],
            intentId: $data['intent_id'],
            amount: Money::of($data['amount'], Currency::from($data['currency'])),
            status: ChargeStatus::from($data['status']),
            provider: $data['provider'],
            createdAt: new DateTimeImmutable('@' . $data['created_at']),
            failureReason: $data['failure_reason'],
            metadata: $metadata,
        );
    }

    private function auditCapture(Charge $charge): void
    {
        $metadata = [
            'provider' => $this->provider->name(),
            'currency' => $charge->amount->currency->value,
            'amount_minor_units' => $charge->amount->amount,
            'intent_id' => $charge->intentId,
            'status' => $charge->status->value,
        ];

        // Stamp tenant id on the audit record when context is wired.
        $tenant = $this->tenantContext?->tryGet();
        if ($tenant !== null) {
            $metadata['tenant_id'] = $tenant->id;
        }

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'capture_intent',
            resource: 'charge:' . $charge->id,
            metadata: $metadata,
        );
    }

    private function incrementCaptureMetric(Charge $charge): void
    {
        $this->metricRegistry->counter(
            'payments_captures_total',
            'Total payment captures',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'currency' => $charge->amount->currency->value,
            'status' => $charge->status->value,
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
            'operation' => 'captureIntent',
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
            'operation' => 'captureIntent',
        ]));
    }
}
