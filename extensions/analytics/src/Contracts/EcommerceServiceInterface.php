<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\EcommerceTransaction;

/**
 * E-commerce analytics tracking and reporting.
 * @api
 */
#[Api(since: '1.0.0')]
interface EcommerceServiceInterface
{
    /**
     * Record a completed transaction.
     */
    public function recordTransaction(EcommerceTransaction $transaction): void;

    /**
     * Get e-commerce summary metrics for a date range.
     *
     * @return array{
     *     revenue: float,
     *     transactions: int,
     *     average_order_value: float,
     *     conversion_rate: float,
     *     items_sold: int,
     *     currency: string,
     * }
     */
    public function getSummary(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;

    /**
     * Get top products by revenue or quantity.
     *
     * @return list<array{product_id: string, name: string, revenue: float, quantity: int}>
     */
    public function getTopProducts(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 10,
    ): array;

    /**
     * Get revenue time-series data.
     *
     * @return list<array{date: string, revenue: float, transactions: int}>
     */
    public function getRevenueTimeseries(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
