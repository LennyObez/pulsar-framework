<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\BillingPortal;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Summary of a stored payment method for display in the billing portal.
 */
#[Api(since: '1.0.0')]
final readonly class PaymentMethodSummary
{
    /**
     * @param string $id Payment method identifier
     * @param string $type Type (e.g., 'card', 'sepa', 'paypal')
     * @param string $last4 Last 4 digits of card/account
     * @param string $brand Card brand (e.g., 'visa', 'mastercard')
     * @param int $expiryMonth Expiry month (1-12)
     * @param int $expiryYear Expiry year (4-digit)
     * @param bool $isDefault Whether this is the default payment method
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $last4,
        public string $brand = '',
        public int $expiryMonth = 0,
        public int $expiryYear = 0,
        public bool $isDefault = false,
    ) {}

    /**
     * Check if the payment method is expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiryYear === 0) {
            return false;
        }

        $now = new DateTimeImmutable();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');

        return $this->expiryYear < $currentYear
            || ($this->expiryYear === $currentYear && $this->expiryMonth < $currentMonth);
    }
}
