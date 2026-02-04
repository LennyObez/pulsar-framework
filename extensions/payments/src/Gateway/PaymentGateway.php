<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use JsonException;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Contracts\IdempotencyStoreInterface;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentRequest;
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
 * Payment gateway orchestrator.
 *
 * Wraps the payment provider with cross-cutting concerns:
 * idempotency enforcement, audit logging, and metrics.
 */
final readonly class PaymentGateway implements PaymentGatewayInterface
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
        private ?CreatePaymentIntentHandler $createHandler = null,
    ) {}

    /**
     * Create a payment intent with idempotency enforcement.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     * @throws JsonException
     */
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        $handler = $this->createHandler ?? new CreatePaymentIntentHandler(
            $this->provider,
            $this->idempotencyStore,
            $this->auditLogger,
            $this->metricRegistry,
            $this->logger,
            $this->clock,
            $this->config,
        );

        return $handler->execute(
            new CreatePaymentIntentRequest($amount, $idempotencyKey, $metadata),
        )->intent;
    }

    /**
     * Capture a payment intent with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     * @throws JsonException
     */
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $this->validateIdempotencyKey($idempotencyKey);

        $parametersHash = ParametersHasher::hash('captureIntent', [
            'intentId' => $intentId,
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $idempotencyKey,
            $parametersHash,
            'captureIntent',
            $this->clock->now(),
            $this->config->idempotency->ttlSeconds,
        );

        if ($claim->status === IdempotencyClaimStatus::Mismatch) {
            throw IdempotencyException::parameterMismatch($idempotencyKey);
        }

        if ($claim->status === IdempotencyClaimStatus::Replay) {
            $this->incrementReplayMetric('captureIntent');

            /** @var string $payload */
            $payload = $claim->resultPayload;

            return $this->deserializeCharge($payload);
        }

        try {
            $charge = $this->provider->captureIntent($intentId, $idempotencyKey);

            $this->commitResult($idempotencyKey, 'charge', $charge);
            $this->auditCapture($charge);
            $this->incrementCaptureMetric($charge);

            return $charge;
        } catch (Throwable $e) {
            $this->idempotencyStore->release($idempotencyKey);
            $this->incrementProviderErrorMetric('captureIntent', $e);

            throw $e;
        }
    }

    /**
     * Cancel a payment intent with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     * @throws JsonException
     */
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        $this->validateIdempotencyKey($idempotencyKey);

        $parametersHash = ParametersHasher::hash('cancelIntent', [
            'intentId' => $intentId,
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $idempotencyKey,
            $parametersHash,
            'cancelIntent',
            $this->clock->now(),
            $this->config->idempotency->ttlSeconds,
        );

        if ($claim->status === IdempotencyClaimStatus::Mismatch) {
            throw IdempotencyException::parameterMismatch($idempotencyKey);
        }

        if ($claim->status === IdempotencyClaimStatus::Replay) {
            $this->incrementReplayMetric('cancelIntent');

            /** @var string $payload */
            $payload = $claim->resultPayload;

            return $this->deserializeIntent($payload);
        }

        try {
            $intent = $this->provider->cancelIntent($intentId, $idempotencyKey);

            $this->commitResult($idempotencyKey, 'payment_intent', $intent);
            $this->auditPayment('cancel_intent', $intent->id, $intent->amount, $intent->status->value);
            $this->incrementIntentMetric($intent->amount, $intent->status->value);

            return $intent;
        } catch (Throwable $e) {
            $this->idempotencyStore->release($idempotencyKey);
            $this->incrementProviderErrorMetric('cancelIntent', $e);

            throw $e;
        }
    }

    /**
     * Refund a charge with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     * @throws JsonException
     */
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $this->validateIdempotencyKey($idempotencyKey);

        $parametersHash = ParametersHasher::hash('refund', [
            'chargeId' => $chargeId,
            'amount_minor' => $amount?->amount ?? 'full',
            'currency' => $amount?->currency->value ?? 'full',
            'provider' => $this->provider->name(),
        ]);

        $claim = $this->idempotencyStore->claim(
            $idempotencyKey,
            $parametersHash,
            'refund',
            $this->clock->now(),
            $this->config->idempotency->ttlSeconds,
        );

        if ($claim->status === IdempotencyClaimStatus::Mismatch) {
            throw IdempotencyException::parameterMismatch($idempotencyKey);
        }

        if ($claim->status === IdempotencyClaimStatus::Replay) {
            $this->incrementReplayMetric('refund');

            /** @var string $payload */
            $payload = $claim->resultPayload;

            return $this->deserializeRefund($payload);
        }

        try {
            $refund = $this->provider->refund($chargeId, $amount, $idempotencyKey);

            $this->commitResult($idempotencyKey, 'refund', $refund);
            $this->auditRefund($refund);
            $this->incrementRefundMetric($refund);

            return $refund;
        } catch (Throwable $e) {
            $this->idempotencyStore->release($idempotencyKey);
            $this->incrementProviderErrorMetric('refund', $e);

            throw $e;
        }
    }

    /**
     * Get a payment intent (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getIntent(string $intentId): PaymentIntent
    {
        return $this->provider->getIntent($intentId);
    }

    /**
     * Get a charge (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getCharge(string $chargeId): Charge
    {
        return $this->provider->getCharge($chargeId);
    }

    /**
     * Get a refund (read-only, no idempotency).
     *
     * @throws PaymentProviderException
     */
    public function getRefund(string $refundId): Refund
    {
        return $this->provider->getRefund($refundId);
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

    private function commitResult(string $key, string $type, PaymentIntent|Charge|Refund $result): void
    {
        $payload = json_encode([
            'schema_version' => 1,
            'type' => $type,
            'data' => $this->serializeResult($result),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $this->idempotencyStore->commit($key, $payload);
        } catch (Throwable $e) {
            $this->logger->critical('Failed to commit idempotency result', [
                'key' => $key,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            throw IdempotencyException::commitFailed($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResult(PaymentIntent|Charge|Refund $result): array
    {
        return match (true) {
            $result instanceof PaymentIntent => [
                'id' => $result->id,
                'amount' => $result->amount->amount,
                'currency' => $result->amount->currency->value,
                'status' => $result->status->value,
                'provider' => $result->provider,
                'idempotency_key' => $result->idempotencyKey,
                'created_at' => $result->createdAt->getTimestamp(),
                'metadata' => $result->metadata,
            ],
            $result instanceof Charge => [
                'id' => $result->id,
                'intent_id' => $result->intentId,
                'amount' => $result->amount->amount,
                'currency' => $result->amount->currency->value,
                'status' => $result->status->value,
                'provider' => $result->provider,
                'created_at' => $result->createdAt->getTimestamp(),
                'failure_reason' => $result->failureReason,
                'metadata' => $result->metadata,
            ],
            $result instanceof Refund => [
                'id' => $result->id,
                'charge_id' => $result->chargeId,
                'amount' => $result->amount->amount,
                'currency' => $result->amount->currency->value,
                'status' => $result->status->value,
                'provider' => $result->provider,
                'created_at' => $result->createdAt->getTimestamp(),
                'failure_reason' => $result->failureReason,
                'metadata' => $result->metadata,
            ],
        };
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

    private function deserializeCharge(string $payload): Charge
    {
        /** @var array{data: array<string, mixed>} $envelope */
        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $data = $envelope['data'];

        return new Charge(
            id: (string) $data['id'],
            intentId: (string) $data['intent_id'],
            amount: Money::of((int) $data['amount'], Currency::from((string) $data['currency'])),
            status: ChargeStatus::from((string) $data['status']),
            provider: (string) $data['provider'],
            createdAt: new DateTimeImmutable('@' . (int) $data['created_at']),
            failureReason: $data['failure_reason'] !== null ? (string) $data['failure_reason'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    private function deserializeRefund(string $payload): Refund
    {
        /** @var array{data: array<string, mixed>} $envelope */
        $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $data = $envelope['data'];

        return new Refund(
            id: (string) $data['id'],
            chargeId: (string) $data['charge_id'],
            amount: Money::of((int) $data['amount'], Currency::from((string) $data['currency'])),
            status: RefundStatus::from((string) $data['status']),
            provider: (string) $data['provider'],
            createdAt: new DateTimeImmutable('@' . (int) $data['created_at']),
            failureReason: $data['failure_reason'] !== null ? (string) $data['failure_reason'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    private function auditPayment(string $action, string $intentId, Money $amount, string $status): void
    {
        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: $action,
            resource: 'payment_intent:' . $intentId,
            metadata: [
                'provider' => $this->provider->name(),
                'currency' => $amount->currency->value,
                'amount_minor_units' => $amount->amount,
                'status' => $status,
            ],
        );
    }

    private function auditCapture(Charge $charge): void
    {
        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'capture_intent',
            resource: 'charge:' . $charge->id,
            metadata: [
                'provider' => $this->provider->name(),
                'currency' => $charge->amount->currency->value,
                'amount_minor_units' => $charge->amount->amount,
                'intent_id' => $charge->intentId,
                'status' => $charge->status->value,
            ],
        );
    }

    private function auditRefund(Refund $refund): void
    {
        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: 'refund',
            resource: 'refund:' . $refund->id,
            metadata: [
                'provider' => $this->provider->name(),
                'currency' => $refund->amount->currency->value,
                'amount_minor_units' => $refund->amount->amount,
                'charge_id' => $refund->chargeId,
                'status' => $refund->status->value,
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

    private function incrementProviderErrorMetric(string $operation, Throwable $e): void
    {
        $errorType = $e instanceof PaymentProviderException ? $e->errorType : 'unknown';

        $this->metricRegistry->counter(
            'payments_provider_errors_total',
            'Total payment provider errors',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'operation' => $operation,
            'error_type' => $errorType,
        ]));
    }

    private function incrementReplayMetric(string $operation): void
    {
        $this->metricRegistry->counter(
            'payments_idempotency_replays_total',
            'Total idempotency replays',
        )->increment(new LabelSet([
            'provider' => $this->provider->name(),
            'operation' => $operation,
        ]));
    }
}
