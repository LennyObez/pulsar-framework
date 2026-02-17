<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An e-commerce transaction tracked by the analytics system.
 */
#[Api(since: '1.0.0')]
final readonly class EcommerceTransaction
{
    /**
     * @param string $id Unique transaction identifier
     * @param string $siteId Site this transaction belongs to
     * @param string $visitorId Pseudonymized visitor identifier
     * @param string $sessionId Session during which the transaction occurred
     * @param string $orderId External order identifier
     * @param float $revenue Total revenue (after tax, before shipping)
     * @param float $tax Tax amount
     * @param float $shipping Shipping cost
     * @param string $currency ISO 4217 currency code
     * @param list<EcommerceItem> $items Products in the transaction
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $visitorId,
        public string $sessionId,
        public string $orderId,
        public float $revenue,
        public float $tax = 0.0,
        public float $shipping = 0.0,
        public string $currency = 'USD',
        public array $items = [],
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
