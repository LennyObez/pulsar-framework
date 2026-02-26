<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Stores processed webhook event IDs for deduplication.
 *
 * Tenant-aware: in multi-tenant deployments, event keys include the tenant ID
 * to prevent cross-tenant collisions.
 */
#[Api(since: '1.0.0')]
interface WebhookDeduplicationStoreInterface
{
    /**
     * Check whether an event has already been processed.
     */
    public function has(string $eventId, ?string $tenantId = null): bool;

    /**
     * Record an event as processed.
     */
    public function store(string $eventId, ?string $tenantId = null): void;

    /**
     * Remove entries older than the given age in days.
     */
    public function cleanup(int $maxAgeDays): void;
}
