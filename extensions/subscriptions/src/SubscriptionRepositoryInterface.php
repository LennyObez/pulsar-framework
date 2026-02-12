<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Pulsar\Api\Api;

/**
 * Persistence contract for subscription entities.
 */
#[Api(since: '1.0.0')]
interface SubscriptionRepositoryInterface
{
    /**
     * Insert or update a subscription record.
     */
    public function save(Subscription $subscription): void;

    /**
     * Find the subscription for a given application user.
     *
     * Returns null if the user has never subscribed.
     */
    public function findByUser(string $userId): ?Subscription;

    /**
     * Look up a subscription by its purchase token hash.
     *
     * Used during verification to detect duplicate tokens.
     */
    public function findByPurchaseTokenHash(string $hash): ?Subscription;

    /**
     * Look up a subscription by the store's original transaction ID.
     *
     * Used during webhook processing to match inbound notifications
     * to existing subscriptions.
     */
    public function findByOriginalTransactionId(string $transactionId): ?Subscription;
}
