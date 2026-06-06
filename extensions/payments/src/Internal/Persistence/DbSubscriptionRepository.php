<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Domain\BillingCycle;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\MobileStore;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\Subscription;
use Pulsar\Extension\Payments\Domain\SubscriptionStatus;

/**
 * Database-backed subscription repository.
 *
 * Supports both web-based and mobile in-app purchase subscriptions.
 */
#[Internal]
final readonly class DbSubscriptionRepository implements SubscriptionRepositoryInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(Subscription $subscription): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO payment_subscriptions (
                    id, customer_id, plan_id, status, billing_cycle, amount, currency,
                    gateway, gateway_subscription_id, purchase_token_hash,
                    original_transaction_id, mobile_store, current_period_start,
                    current_period_end, trial_end, cancelled_at, grace_period_until,
                    created_at, updated_at
                ) VALUES (
                    :id, :customer_id, :plan_id, :status, :billing_cycle, :amount, :currency,
                    :gateway, :gateway_subscription_id, :purchase_token_hash,
                    :original_transaction_id, :mobile_store, :current_period_start,
                    :current_period_end, :trial_end, :cancelled_at, :grace_period_until,
                    :created_at, :updated_at
                ) ON CONFLICT (id) DO UPDATE SET
                    status = :status, current_period_end = :current_period_end,
                    cancelled_at = :cancelled_at, grace_period_until = :grace_period_until,
                    updated_at = :updated_at
                SQL,
            [
                'id' => $subscription->id,
                'customer_id' => $subscription->customerId,
                'plan_id' => $subscription->planId,
                'status' => $subscription->status->value,
                'billing_cycle' => $subscription->billingCycle->value,
                'amount' => $subscription->amount->amount,
                'currency' => $subscription->amount->currency->value,
                'gateway' => $subscription->gateway,
                'gateway_subscription_id' => $subscription->gatewaySubscriptionId,
                'purchase_token_hash' => $subscription->purchaseTokenHash,
                'original_transaction_id' => $subscription->originalTransactionId,
                'mobile_store' => $subscription->mobileStore?->value,
                'current_period_start' => $subscription->currentPeriodStart?->format('c'),
                'current_period_end' => $subscription->currentPeriodEnd?->format('c'),
                'trial_end' => $subscription->trialEnd?->format('c'),
                'cancelled_at' => $subscription->cancelledAt?->format('c'),
                'grace_period_until' => $subscription->gracePeriodUntil?->format('c'),
                'created_at' => $subscription->createdAt->format('c'),
                'updated_at' => $subscription->updatedAt->format('c'),
            ],
        );
    }

    public function findById(string $id): ?Subscription
    {
        $result = $this->connection->query(
            'SELECT * FROM payment_subscriptions WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<Subscription>
     */
    public function findByCustomer(string $customerId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM payment_subscriptions WHERE customer_id = :customer_id ORDER BY created_at DESC',
            ['customer_id' => $customerId],
        );

        return $result->map(self::hydrate(...));
    }

    public function findByGatewayId(string $gatewaySubscriptionId): ?Subscription
    {
        $result = $this->connection->query(
            'SELECT * FROM payment_subscriptions WHERE gateway_subscription_id = :gid LIMIT 1',
            ['gid' => $gatewaySubscriptionId],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByOriginalTransactionId(string $transactionId): ?Subscription
    {
        $result = $this->connection->query(
            'SELECT * FROM payment_subscriptions WHERE original_transaction_id = :tid LIMIT 1',
            ['tid' => $transactionId],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByPurchaseTokenHash(string $hash): ?Subscription
    {
        $result = $this->connection->query(
            'SELECT * FROM payment_subscriptions WHERE purchase_token_hash = :hash LIMIT 1',
            ['hash' => $hash],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<Subscription>
     */
    public function findDueForRenewal(DateTimeImmutable $date): array
    {
        $dateStr = $date->format('Y-m-d');

        $result = $this->connection->query(
            <<<'SQL'
                SELECT * FROM payment_subscriptions
                WHERE status = :status
                  AND DATE(current_period_end) = :date
                ORDER BY created_at ASC
                SQL,
            [
                'status' => SubscriptionStatus::Active->value,
                'date' => $dateStr,
            ],
        );

        return $result->map(self::hydrate(...));
    }

    private static function hydrate(Row $row): Subscription
    {
        $mobileStore = $row->getNullableString('mobile_store');

        return new Subscription(
            id: $row->getString('id'),
            customerId: $row->getString('customer_id'),
            planId: $row->getString('plan_id'),
            status: SubscriptionStatus::from($row->getString('status')),
            billingCycle: BillingCycle::from($row->getString('billing_cycle')),
            amount: Money::of($row->getInt('amount'), Currency::from($row->getString('currency'))),
            gateway: $row->getNullableString('gateway'),
            gatewaySubscriptionId: $row->getNullableString('gateway_subscription_id'),
            purchaseTokenHash: $row->getNullableString('purchase_token_hash'),
            originalTransactionId: $row->getNullableString('original_transaction_id'),
            mobileStore: $mobileStore !== null ? MobileStore::from($mobileStore) : null,
            currentPeriodStart: self::toDateTime($row->getNullableString('current_period_start')),
            currentPeriodEnd: self::toDateTime($row->getNullableString('current_period_end')),
            trialEnd: self::toDateTime($row->getNullableString('trial_end')),
            cancelledAt: self::toDateTime($row->getNullableString('cancelled_at')),
            gracePeriodUntil: self::toDateTime($row->getNullableString('grace_period_until')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
