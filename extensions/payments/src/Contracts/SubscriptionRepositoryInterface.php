<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Subscription;

/**
 * Repository for persisting and retrieving subscriptions.
 */
#[Api(since: '1.0.0')]
interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;

    public function findById(string $id): ?Subscription;

    /**
     * @return list<Subscription>
     */
    public function findByCustomer(string $customerId): array;

    public function findByGatewayId(string $gatewaySubscriptionId): ?Subscription;

    public function findByOriginalTransactionId(string $transactionId): ?Subscription;

    public function findByPurchaseTokenHash(string $hash): ?Subscription;

    /**
     * Find all active subscriptions due for renewal on the given date.
     *
     * Returns subscriptions whose currentPeriodEnd falls on the specified date.
     *
     * @return list<Subscription>
     */
    public function findDueForRenewal(DateTimeImmutable $date): array;
}
