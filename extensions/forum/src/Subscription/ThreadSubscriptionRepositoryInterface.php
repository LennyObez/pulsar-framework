<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Subscription;

use Pulsar\Api\Api;

/**
 * Repository interface for thread subscriptions.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThreadSubscriptionRepositoryInterface
{
    public function findById(string $id): ?ThreadSubscription;

    /**
     * Find a user's subscription to a specific thread.
     */
    public function findByUserAndThread(string $userId, string $threadId): ?ThreadSubscription;

    /**
     * Find all subscriptions for a thread (for notification dispatch).
     *
     * @return list<ThreadSubscription>
     */
    public function findByThread(string $threadId): array;

    /**
     * Find all threads a user is subscribed to.
     *
     * @return list<ThreadSubscription>
     */
    public function findByUser(string $userId, ?string $tenantId = null): array;

    /**
     * Check if a user is subscribed to a thread.
     */
    public function isSubscribed(string $userId, string $threadId): bool;

    public function save(ThreadSubscription $subscription): void;

    public function delete(ThreadSubscription $subscription): void;
}
