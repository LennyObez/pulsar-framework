<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Persistence contract for webhook event records.
 * @api
 */
#[Api(since: '1.0.0')]
interface WebhookEventRepositoryInterface
{
    /**
     * Persist a new or updated webhook event.
     */
    public function save(WebhookEvent $event): void;

    /**
     * Query webhook events by event type with pagination.
     *
     * @return PaginationResult<WebhookEvent>
     */
    public function findByEventType(string $eventType, int $page = 1, int $perPage = 20): PaginationResult;
}
