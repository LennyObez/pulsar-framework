<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use SodiumException;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
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
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentRequest;
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
        // F21.3: signs/verifies idempotency-cache payloads with a
        // master-key-derived HMAC. Required: a compromised store row
        // must not be replay-able as a forged response, so the gateway
        // refuses to construct without an envelope signer.
        private SignedIdempotencyEnvelope $envelope,
        // F22.2: the create-intent handler is now a required dependency.
        // Earlier revisions accepted `?CreatePaymentIntentHandler = null`
        // and instantiated a fresh handler inline — a hidden service-
        // locator pattern that hardcoded the handler's constructor
        // signature inside the gateway. PaymentsServiceProvider already
        // binds the handler in the container, so production wiring was
        // never affected; the only callers exercising the null branch
        // were tests that passed `createHandler: null` explicitly. Force
        // the typed dependency so any future change to the handler
        // constructor reaches every call site through the type system
        // rather than silently breaking the inline fallback.
        private CreatePaymentIntentHandler $createHandler,
        // F13.9: when a `TenantContext` is wired, the gateway stamps the
        // tenant id on every audit record so a multi-tenant audit trail
        // can be filtered per tenant. Idempotency-key tenant scoping is
        // handled separately by the `TenantAwareIdempotencyStore` decorator
        // wired around the underlying store. Optional — single-tenant
        // deployments leave it null.
        private ?TenantContext $tenantContext = null,
    ) {}

    /**
     * Create a payment intent with idempotency enforcement.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function createIntent(Money $amount, string $idempotencyKey, array $metadata = []): PaymentIntent
    {
        $this->assertTenantScope('createIntent');

        return $this->createHandler->execute(
            new CreatePaymentIntentRequest($amount, $idempotencyKey, $metadata),
        )->intent;
    }

    /**
     * Capture a payment intent with idempotency enforcement.
     *
     * @throws IdempotencyException
     * @throws PaymentProviderException
     */
    public function captureIntent(string $intentId, string $idempotencyKey): Charge
    {
        $this->assertTenantScope('captureIntent');
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

            return $this->deserializeCharge($idempotencyKey, $payload);
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
     */
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntent
    {
        $this->assertTenantScope('cancelIntent');
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

            return $this->deserializeIntent($idempotencyKey, $payload);
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
     */
    public function refund(string $chargeId, ?Money $amount, string $idempotencyKey): Refund
    {
        $this->assertTenantScope('refund');
        $this->validateIdempotencyKey($idempotencyKey);

        $parametersHash = ParametersHasher::hash('refund', [
            'chargeId' => $chargeId,
            'amount_minor' => $amount !== null ? $amount->amount : 'full',
            'currency' => $amount !== null ? $amount->currency->value : 'full',
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

            return $this->deserializeRefund($idempotencyKey, $payload);
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
     * F13.10: refuse mutating payment operations that run outside a
     * tenant scope when the deployment is configured to require one.
     *
     * Read-only `getIntent` / `getCharge` / `getRefund` are intentionally
     * unguarded — they are safe to invoke from health checks / admin
     * tooling that may legitimately operate without a tenant context.
     *
     * @throws PaymentException When `requireTenantContext` is true and
     *                          no tenant is currently resolved.
     */
    private function assertTenantScope(string $operation): void
    {
        if (!$this->config->requireTenantContext) {
            return;
        }

        if ($this->tenantContext === null || $this->tenantContext->tryGet() === null) {
            throw PaymentException::missingTenantContext($operation);
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

    private function commitResult(string $key, string $type, PaymentIntent|Charge|Refund $result): void
    {
        // F22.6: encode + seal can throw JsonException / SodiumException
        // — both are framework-internal serialisation details. Wrap into
        // the domain `IdempotencyException::serializationFailed()` so
        // the consumer-facing interface only ever leaks IdempotencyException
        // / PaymentProviderException as documented.
        try {
            $payload = json_encode([
                'schema_version' => 1,
                'type' => $type,
                'data' => $this->serializeResult($result),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            // F21.3: bind the payload to the idempotency key with a HMAC
            // envelope so a tampered store row cannot replay a forged result.
            $sealed = $this->envelope->seal($key, $payload);
        } catch (JsonException | SodiumException $e) {
            throw IdempotencyException::serializationFailed($key, $e);
        }

        try {
            $this->idempotencyStore->commit($key, $sealed);
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

    private function auditPayment(string $action, string $intentId, Money $amount, string $status): void
    {
        $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'payments',
            action: $action,
            resource: 'payment_intent:' . $intentId,
            metadata: $this->withTenantMetadata([
                'provider' => $this->provider->name(),
                'currency' => $amount->currency->value,
                'amount_minor_units' => $amount->amount,
                'status' => $status,
            ]),
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
            metadata: $this->withTenantMetadata([
                'provider' => $this->provider->name(),
                'currency' => $charge->amount->currency->value,
                'amount_minor_units' => $charge->amount->amount,
                'intent_id' => $charge->intentId,
                'status' => $charge->status->value,
            ]),
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
            metadata: $this->withTenantMetadata([
                'provider' => $this->provider->name(),
                'currency' => $refund->amount->currency->value,
                'amount_minor_units' => $refund->amount->amount,
                'charge_id' => $refund->chargeId,
                'status' => $refund->status->value,
            ]),
        );
    }

    /**
     * Stamp the active tenant id onto an audit metadata bag.
     *
     * F13.9: a multi-tenant audit trail must record which tenant
     * initiated each payment operation so compliance reports and
     * incident replays can be filtered per tenant. When no tenant is
     * resolved (single-tenant deploy or bootstrap call), the metadata
     * is returned unchanged.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function withTenantMetadata(array $metadata): array
    {
        $tenant = $this->tenantContext?->tryGet();

        if ($tenant !== null) {
            $metadata['tenant_id'] = $tenant->id;
        }

        return $metadata;
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
