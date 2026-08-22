<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Internal\Persistence;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\Subscription;
use Pulsar\Extension\Subscriptions\SubscriptionRepositoryInterface;
use Pulsar\Extension\Subscriptions\SubscriptionStatus;

/**
 * Database-backed subscription repository.
 *
 * Uses portable upserts (INSERT ... ON CONFLICT / ON DUPLICATE KEY) so
 * concurrent verifications for the same purchase token are safe.
 */
#[Internal(reason: 'Raw-DB repository; use SubscriptionRepositoryInterface for public API')]
final readonly class DbSubscriptionRepository implements SubscriptionRepositoryInterface
{
    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT s.*
        FROM subscriptions s
        WHERE s.user_id = :user_id
        ORDER BY s.created_at DESC
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_TOKEN_HASH = <<<'SQL'
        SELECT s.*
        FROM subscriptions s
        WHERE s.purchase_token_hash = :purchase_token_hash
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_TRANSACTION_ID = <<<'SQL'
        SELECT s.*
        FROM subscriptions s
        WHERE s.original_transaction_id = :original_transaction_id
        LIMIT 1
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'user_id', 'store', 'product_id', 'plan', 'status',
        'purchase_token_hash', 'raw_receipt_encrypted', 'original_transaction_id',
        'expires_at', 'grace_period_until', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'user_id', 'store', 'product_id', 'plan', 'status',
        'raw_receipt_encrypted', 'expires_at', 'grace_period_until', 'updated_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function save(Subscription $subscription): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'subscriptions',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $subscription->id,
            'user_id' => $subscription->userId,
            'store' => $subscription->store->value,
            'product_id' => $subscription->productId,
            'plan' => $subscription->plan,
            'status' => $subscription->status->value,
            'purchase_token_hash' => $subscription->purchaseTokenHash,
            'raw_receipt_encrypted' => $subscription->rawReceiptEncrypted,
            'original_transaction_id' => $subscription->originalTransactionId,
            'expires_at' => $subscription->expiresAt?->format('c'),
            'grace_period_until' => $subscription->gracePeriodUntil?->format('c'),
            'created_at' => $subscription->createdAt->format('c'),
            'updated_at' => $subscription->updatedAt->format('c'),
        ]);
    }

    #[Override]
    public function findByUser(string $userId): ?Subscription
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, ['user_id' => $userId]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    #[Override]
    public function findByPurchaseTokenHash(string $hash): ?Subscription
    {
        $result = $this->connection->query(self::SQL_FIND_BY_TOKEN_HASH, [
            'purchase_token_hash' => $hash,
        ]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    #[Override]
    public function findByOriginalTransactionId(string $transactionId): ?Subscription
    {
        $result = $this->connection->query(self::SQL_FIND_BY_TRANSACTION_ID, [
            'original_transaction_id' => $transactionId,
        ]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    private static function hydrate(Row $row): Subscription
    {
        return new Subscription(
            id: $row->getString('id'),
            userId: $row->getString('user_id'),
            store: Store::from($row->getString('store')),
            productId: $row->getString('product_id'),
            plan: $row->getString('plan'),
            status: SubscriptionStatus::from($row->getString('status')),
            purchaseTokenHash: $row->getString('purchase_token_hash'),
            rawReceiptEncrypted: $row->getNullableString('raw_receipt_encrypted'),
            originalTransactionId: $row->getString('original_transaction_id'),
            expiresAt: self::toDateTime($row->getNullableString('expires_at')),
            gracePeriodUntil: self::toDateTime($row->getNullableString('grace_period_until')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
