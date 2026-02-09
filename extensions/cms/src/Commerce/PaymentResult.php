<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Result of a payment processing attempt.
 */
#[Api(since: '1.0.0')]
final readonly class PaymentResult
{
    /**
     * @param bool $success Whether the payment was completed synchronously
     * @param string $orderId The order ID this payment relates to
     * @param string|null $paymentIntentId External payment provider reference
     * @param bool $requiresRedirect Whether the customer must be redirected for 3DS/SCA
     * @param string|null $redirectUrl URL to redirect the customer to for payment completion
     */
    public function __construct(
        public bool $success,
        public string $orderId,
        public ?string $paymentIntentId,
        public bool $requiresRedirect,
        public ?string $redirectUrl,
    ) {}
}
