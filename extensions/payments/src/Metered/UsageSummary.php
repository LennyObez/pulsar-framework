<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Metered;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Aggregated usage summary for a billing period.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UsageSummary
{
    /**
     * @param string $subscriptionId Subscription identifier
     * @param string $metricKey Metric identifier
     * @param int $totalQuantity Total usage quantity in the period
     * @param Money $totalCost Calculated cost for this usage
     * @param int $includedQuantity Quantity included in the base plan
     * @param int $overageQuantity Usage exceeding the included amount
     */
    public function __construct(
        public string $subscriptionId,
        public string $metricKey,
        public int $totalQuantity,
        public Money $totalCost,
        public int $includedQuantity = 0,
        public int $overageQuantity = 0,
        public DateTimeImmutable $periodStart = new DateTimeImmutable(),
        public DateTimeImmutable $periodEnd = new DateTimeImmutable(),
    ) {}

    /**
     * Check if usage exceeds the included amount.
     */
    public function hasOverage(): bool
    {
        return $this->overageQuantity > 0;
    }
}
