<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Service interface for the checkout flow: cart validation, order creation, and payment.
 */
#[Api(since: '1.0.0')]
interface CheckoutServiceInterface
{
    /**
     * Validate cart items: verify products exist, are active, have sufficient stock, and prices are current.
     *
     * @param list<array{productId: string, quantity: int, unitPrice: int, variantId?: string|null}> $cartItems
     */
    public function validateCart(array $cartItems): CartValidationResult;

    /**
     * Create an order from validated cart items.
     *
     * @param list<array{productId: string, quantity: int, unitPrice: int, variantId?: string|null}> $cartItems
     * @param array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string} $billingAddress
     * @param array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string}|null $shippingAddress
     */
    public function createOrder(
        array $cartItems,
        string $customerEmail,
        array $billingAddress,
        ?array $shippingAddress = null,
        ?string $couponCode = null,
        ?string $customerId = null,
        ?string $tenantId = null,
    ): Order;

    /**
     * Process payment for an existing order.
     *
     * @param array<string, mixed> $paymentData Provider-specific payment data
     */
    public function processPayment(string $orderId, array $paymentData): PaymentResult;

    /**
     * Cancel an in-progress checkout, releasing any reserved stock.
     *
     * @param string $orderId The order to cancel
     * @param string|null $actorId The user performing the cancellation, for audit logging
     */
    public function cancelCheckout(string $orderId, ?string $actorId = null): void;
}
