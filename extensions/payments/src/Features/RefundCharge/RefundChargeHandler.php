<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Features\RefundCharge;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
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
 * Refund-charge slice handler.
 *
 * Mirrors the {@see CreatePaymentIntentHandler} structure
 * (Handler + Request + Result).
 */
final readonly class RefundChargeHandler
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
    public function execute(RefundChargeRequest $request): RefundChargeResult
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        $parametersHash = ParametersHasher::hash('refund', [
            'chargeId' => $request->chargeId,
            'amount_minor' => $request->amount !== null ? $request->amount->amount : 'full',
            'currency' => $request->amount !== null ? $request->amount->currency->value : 'full',
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $request->idempotencyKey,
            $parametersHash,
            'refund',
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

            return new RefundChargeResult(
                refund: $this->deserializeRefund($request->idempotencyKey, $payload),
                replayed: true,
            );
        }

        try {
            $refund = $this->provider->refund(
                $request->chargeId,
                $request->amount,
                $request->idempotencyKey,
            );

            $this->commitResult($request->idempotencyKey, $refund);
            $this->auditRefund($refund);
            $this->incrementRefundMetric($refund);

            return new RefundChargeResult(refund: $refund);
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

    private function commitResult(string $key, Refund $refund): void
    {
        try {
            $payload = json_encode([
                'schema_version' => 1,
                'type' => 'refund',
                'data' => [
                    'id' => $refund->id,
                    'charge_id' => $refund->chargeId,
                    'amount' => $refund->amount->amount,
                    'currency' => $refund->amount->currency->value,
                    'status' => $refund->status->value,
                    'provider' => $refund->provider,
                    'created_at' => $refund->createdAt->getTimestamp(),
                    'failure_reason' => $refund->failureReason,
                    'metadata' => $refund->metadata,
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
                'type' => 'refund',
                'error' => $e->getMessage(),
            ]);

            throw IdempotencyException::commitFailed($key);
        }
    }

    private function deserializeRefund(string $idempotencyKey, string $sealed): Refund
    {
        try {
            $payload = $this->envelope->open($idempotencyKey, $sealed);

            /** @var array{data: array{id: string, charge_id: string, amount: int, currency: string, status: string, provider: string, created_at: int, failure_reason: string|null, metadata?: array<string, mixed>}} $envelope */
            $envelope = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException | SodiumException $e) {
            throw IdempotencyException::serializationFailed($idempotencyKey, $e);
        }

        $data = $envelope['data'];

        /** @var array<string, mixed> $metadata */
        $metadata = $data['metadata'] ?? [];

        return new Refund(
            id: $data['id'],
            chargeId: $data['charge_id'],
            amount: Money::of($data['amount'], Currency::from($data['currency'])),
            status: RefundStatus::from($data['status']),
            provider: $data['provider'],
            createdAt: new DateTimeImmutable('@' . $data['created_at']),
            failureReason: $data['failure_reason'],
            metadata: $metadata,
        );
    }

    private function auditRefund(Refund $refund): void
    {
        $metadata = [
            'provider' => $this->provider->name(),
            'currency' => $refund->amount->currency->value,
            'amount_minor_units' => $refund->amount->amount,
            'charge_id' => $refund->chargeId,
            'status' => $refund->status->value,
        ];

        $tenant = $this->tenantContext?->tryGet();
        if ($tenant !== null) {
            $metadata['tenant_id'] = $tenant->id;
        }

        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'refund',
            resource: 'refund:' . $refund->id,
            metadata: $metadata,
        );
    }

    private function incrementRefundMetric(Refund $refund): void
    {
        $this->metricRegistry->counter(
            'payments_refunds_total',
            'Total payment refunds',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'currency' => $refund->amount->currency->value,
            'status' => $refund->status->value,
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
            'operation' => 'refund',
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
            'operation' => 'refund',
        ]));
    }
}
