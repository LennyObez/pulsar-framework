<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Metered;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A recorded usage event for metered billing.
 */
#[Api(since: '1.0.0')]
final readonly class UsageRecord
{
    /**
     * @param string $id Unique record identifier
     * @param string $subscriptionId Subscription being metered
     * @param string $metricKey Metric identifier (e.g., 'api_calls', 'storage_gb', 'messages')
     * @param int $quantity Usage quantity for this record
     * @param string $idempotencyKey Client-provided key to prevent duplicate recording
     */
    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $metricKey,
        public int $quantity,
        public string $idempotencyKey = '',
        public DateTimeImmutable $recordedAt = new DateTimeImmutable(),
    ) {}
}
