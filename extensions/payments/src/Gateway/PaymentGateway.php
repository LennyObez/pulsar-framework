<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Contracts\PaymentGatewayInterface;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Charge;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\Refund;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Features\CancelPaymentIntent\CancelPaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CancelPaymentIntent\CancelPaymentIntentRequest;
use Pulsar\Extension\Payments\Features\CapturePaymentIntent\CapturePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CapturePaymentIntent\CapturePaymentIntentRequest;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentHandler;
use Pulsar\Extension\Payments\Features\CreatePaymentIntent\CreatePaymentIntentRequest;
use Pulsar\Extension\Payments\Features\RefundCharge\RefundChargeHandler;
use Pulsar\Extension\Payments\Features\RefundCharge\RefundChargeRequest;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Tenancy\TenantContext;

/**
 * Payment gateway orchestrator.
 *
 * F22.1: a thin facade that delegates each mutating operation to its
 * own vertical slice handler ({@see CreatePaymentIntentHandler},
 * {@see CapturePaymentIntentHandler}, {@see CancelPaymentIntentHandler},
 * {@see RefundChargeHandler}). The slices own their idempotency claim,
 * provider call, audit, and metrics — the gateway only enforces the
 * read-through delegations and the tenant-scope precondition that
 * applies to the whole `PaymentGatewayInterface` contract (F13.10).
 *
 * Read-only `getIntent` / `getCharge` / `getRefund` go straight to the
 * provider — they do not need idempotency or audit, and they are safe
 * to invoke from health checks and admin tooling without a tenant
 * context.
 */
final readonly class PaymentGateway implements PaymentGatewayInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private CreatePaymentIntentHandler $createHandler,
        private CapturePaymentIntentHandler $captureHandler,
        private CancelPaymentIntentHandler $cancelHandler,
        private RefundChargeHandler $refundHandler,
        // Read-only getters bypass the slice handlers and call the
        // provider directly — they need neither idempotency nor audit.
        private PaymentProviderInterface $provider,
        private PaymentsConfig $config,
        // F13.10: when `requireTenantContext` is true, every mutating
        // operation must run inside a resolved tenant scope. Read-only
        // getters do not enforce this.
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

        return $this->captureHandler->execute(
            new CapturePaymentIntentRequest($intentId, $idempotencyKey),
        )->charge;
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

        return $this->cancelHandler->execute(
            new CancelPaymentIntentRequest($intentId, $idempotencyKey),
        )->intent;
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

        return $this->refundHandler->execute(
            new RefundChargeRequest($chargeId, $amount, $idempotencyKey),
        )->refund;
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
}
