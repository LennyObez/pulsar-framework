<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Metered;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Api;

/**
 * Service for metered/usage-based billing.
 *
 * Records usage events and calculates costs based on pricing tiers.
 */
#[Api(since: '1.0.0')]
interface MeteredBillingServiceInterface
{
    /**
     * Record a usage event.
     *
     * @throws InvalidArgumentException If quantity is not positive
     */
    public function recordUsage(
        string $subscriptionId,
        string $metricKey,
        int $quantity,
        string $idempotencyKey = '',
    ): UsageRecord;

    /**
     * Get usage summary for a subscription in a billing period.
     */
    public function getSummary(
        string $subscriptionId,
        string $metricKey,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): UsageSummary;

    /**
     * Get all usage records for a subscription in a period.
     *
     * @return list<UsageRecord>
     */
    public function getRecords(
        string $subscriptionId,
        string $metricKey,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;

    /**
     * Get current period usage total for a metric.
     */
    public function getCurrentUsage(string $subscriptionId, string $metricKey): int;
}
