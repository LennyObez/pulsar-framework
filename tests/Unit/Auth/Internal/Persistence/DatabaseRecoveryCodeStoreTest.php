<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Internal\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Internal\Persistence\DatabaseRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use RuntimeException;
use Throwable;

#[CoversClass(DatabaseRecoveryCodeStore::class)]
final class DatabaseRecoveryCodeStoreTest extends TestCase
{
    private PDO $pdo;
    private DatabaseRecoveryCodeStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE auth_recovery_codes (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                code_hash VARCHAR(128) NOT NULL,
                used_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX idx_recovery_codes_user ON auth_recovery_codes (user_id)
            SQL);

        $connection = $this->createConnectionStub();
        $this->store = new DatabaseRecoveryCodeStore($connection);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS auth_recovery_codes');
    }

    #[Test]
    public function loadSet_returns_null_for_unknown_identity(): void
    {
        self::assertNull($this->store->loadSet('unknown-user'));
    }

    #[Test]
    public function store_and_loadSet_round_trips_recovery_codes(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-001',
            codeHashes: ['hash-a', 'hash-b', 'hash-c'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1709800000,
        );

        $this->store->store('user-1', $set);
        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame('set-001', $loaded->setId);
        self::assertSame(['hash-a', 'hash-b', 'hash-c'], $loaded->codeHashes);
        self::assertSame([], $loaded->usedIndices);
        self::assertSame(2, $loaded->algorithmVersion);
        self::assertSame(1709800000, $loaded->createdAt);
    }

    #[Test]
    public function store_overwrites_existing_set(): void
    {
        $set1 = new RecoveryCodeSet('set-001', ['hash-1'], [], 2, 1709800000);
        $set2 = new RecoveryCodeSet('set-002', ['hash-2', 'hash-3'], [], 2, 1709800100);

        $this->store->store('user-1', $set1);
        $this->store->store('user-1', $set2);

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame('set-002', $loaded->setId);
        self::assertSame(['hash-2', 'hash-3'], $loaded->codeHashes);

        // Verify old codes are gone
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM auth_recovery_codes WHERE user_id = ?');
        $stmt->execute(['user-1']);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    #[Test]
    public function consume_succeeds_for_valid_unused_code(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a', 'hash-b', 'hash-c'], [], 2, 1709800000);
        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'hash-b');

        self::assertTrue($result->consumed);
        self::assertSame(1, $result->codeIndex);
        self::assertSame(ConsumeReason::Consumed, $result->reason);
    }

    #[Test]
    public function consume_returns_not_enrolled_for_unknown_identity(): void
    {
        $result = $this->store->consume('unknown', 'any-hash');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function consume_returns_not_found_for_nonexistent_hash(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a', 'hash-b'], [], 2, 1709800000);
        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'hash-nonexistent');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotFound, $result->reason);
    }

    #[Test]
    public function consume_returns_already_used_for_consumed_code(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a', 'hash-b'], [], 2, 1709800000);
        $this->store->store('user-1', $set);

        // First consume succeeds
        $first = $this->store->consume('user-1', 'hash-a');
        self::assertTrue($first->consumed);

        // Second consume of the same code fails
        $second = $this->store->consume('user-1', 'hash-a');
        self::assertFalse($second->consumed);
        self::assertSame(ConsumeReason::AlreadyUsed, $second->reason);
    }

    #[Test]
    public function consume_marks_used_at_timestamp(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a'], [], 2, 1709800000);
        $this->store->store('user-1', $set);

        $this->store->consume('user-1', 'hash-a');

        $stmt = $this->pdo->prepare('SELECT used_at FROM auth_recovery_codes WHERE code_hash = ?');
        $stmt->execute(['hash-a']);
        $usedAt = $stmt->fetchColumn();

        self::assertNotNull($usedAt);
        self::assertNotEmpty($usedAt);
    }

    #[Test]
    public function loadSet_reflects_consumed_codes_via_used_indices(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a', 'hash-b', 'hash-c'], [], 2, 1709800000);
        $this->store->store('user-1', $set);

        $this->store->consume('user-1', 'hash-a');
        $this->store->consume('user-1', 'hash-c');

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame([0, 2], $loaded->usedIndices);
        self::assertSame(1, $loaded->remainingCount());
    }

    #[Test]
    public function different_identities_are_isolated(): void
    {
        $set1 = new RecoveryCodeSet('set-001', ['hash-a'], [], 2, 1709800000);
        $set2 = new RecoveryCodeSet('set-002', ['hash-b'], [], 2, 1709800000);

        $this->store->store('user-1', $set1);
        $this->store->store('user-2', $set2);

        $loaded1 = $this->store->loadSet('user-1');
        $loaded2 = $this->store->loadSet('user-2');

        self::assertNotNull($loaded1);
        self::assertNotNull($loaded2);
        self::assertSame('set-001', $loaded1->setId);
        self::assertSame('set-002', $loaded2->setId);
    }

    #[Test]
    public function store_preserves_used_indices_from_set(): void
    {
        $set = new RecoveryCodeSet('set-001', ['hash-a', 'hash-b', 'hash-c'], [0, 2], 2, 1709800000);
        $this->store->store('user-1', $set);

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame([0, 2], $loaded->usedIndices);
    }

    /**
     * Create a ConnectionInterface stub that delegates to the real PDO.
     */
    private function createConnectionStub(): ConnectionInterface
    {
        $pdo = $this->pdo;

        return new class ($pdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo) {}

            public function query(string $sql, array $bindings = []): Result
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);
                /** @var list<array<string, mixed>> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return Result::fromArrays($rows);
            }

            public function execute(string $sql, array $bindings = []): int
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);

                return $stmt->rowCount();
            }

            public function prepare(string $sql): \Pulsar\Database\Statement
            {
                throw new RuntimeException('Not implemented');
            }

            public function beginTransaction(): \Pulsar\Database\Transaction
            {
                throw new RuntimeException('Not implemented');
            }

            public function transaction(callable $callback): mixed
            {
                $this->pdo->beginTransaction();
                try {
                    $result = $callback($this);
                    $this->pdo->commit();
                    return $result;
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }

            public function lastInsertId(): string
            {
                return $this->pdo->lastInsertId() ?: '0';
            }

            public function driver(): Driver
            {
                return Driver::SQLite;
            }

            public function variant(): \Pulsar\Database\DriverVariant
            {
                return \Pulsar\Database\DriverVariant::Standard;
            }

            public function dialect(): \Pulsar\Database\Dialect\DialectInterface
            {
                return \Pulsar\Database\Dialect\Dialects::for($this->driver(), $this->variant());
            }

            public function name(): string
            {
                return 'test';
            }

            public function inTransaction(): bool
            {
                return $this->pdo->inTransaction();
            }

            public function disconnect(): void {}
        };
    }
}
