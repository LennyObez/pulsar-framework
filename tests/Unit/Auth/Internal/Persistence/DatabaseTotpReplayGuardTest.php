<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Internal\Persistence;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Internal\Persistence\DatabaseTotpReplayGuard;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Result;
use RuntimeException;
use Throwable;

#[CoversClass(DatabaseTotpReplayGuard::class)]
final class DatabaseTotpReplayGuardTest extends TestCase
{
    private PDO $pdo;
    private DatabaseTotpReplayGuard $guard;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Mirrors 20260327000001_create_2fa_tables.php: the key omits `purpose`.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE auth_totp_replay_guard (
                user_id VARCHAR(36) NOT NULL,
                time_step INTEGER NOT NULL,
                used_at TEXT NOT NULL,
                PRIMARY KEY (user_id, time_step)
            )
            SQL);

        $connection = $this->createConnectionStub();
        $this->guard = new DatabaseTotpReplayGuard($connection);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS auth_totp_replay_guard');
    }

    #[Test]
    public function markUsed_returns_true_for_first_use(): void
    {
        $result = $this->guard->markUsed('user-001', 100, 1709800000);

        self::assertTrue($result);
    }

    #[Test]
    public function markUsed_returns_false_for_replayed_time_step(): void
    {
        $this->guard->markUsed('user-001', 100, 1709800000);

        $result = $this->guard->markUsed('user-001', 100, 1709800001);

        self::assertFalse($result);
    }

    /**
     * The key omits the purpose, so the Login/Setup/StepUp sequence that a
     * purpose-keyed table sold three times now sells once (ASVS 2.8.4).
     */
    #[Test]
    public function one_time_step_is_redeemable_once_however_many_flows_ask(): void
    {
        self::assertTrue($this->guard->markUsed('user-001', 100, 1709800000));
        self::assertFalse($this->guard->markUsed('user-001', 100, 1709800000));
        self::assertFalse($this->guard->markUsed('user-001', 100, 1709800000));
    }

    #[Test]
    public function replay_at_a_61_second_gap_is_rejected(): void
    {
        // At shipped defaults the verifier accepts a code for 90 s. A guard that
        // retained only 2 periods forgot at 60 s and handed over the remaining 30.
        self::assertTrue($this->guard->markUsed('user-001', 100, 1709800000));

        // Enough traffic at the replay instant to make the 1-in-20 prune lottery
        // a certainty, so the assertion below turns on retention and not on luck.
        for ($i = 0; $i < 300; $i++) {
            $this->guard->markUsed('user-001', 5000 + $i, 1709800061);
        }

        self::assertFalse($this->guard->markUsed('user-001', 100, 1709800061));
    }

    #[Test]
    public function replay_is_rejected_at_every_instant_in_the_envelope(): void
    {
        for ($gap = 0; $gap <= 89; $gap++) {
            // One identity per gap so each pair starts from an empty record.
            self::assertTrue($this->guard->markUsed('user-' . $gap, 100, 1709800000));
            self::assertFalse($this->guard->markUsed('user-' . $gap, 100, 1709800000 + $gap), 'gap ' . $gap);
        }
    }

    #[Test]
    public function retention_scales_with_the_verification_window(): void
    {
        // period 30, window 2: the verifier accepts the code for 150 s.
        $guard = new DatabaseTotpReplayGuard($this->createConnectionStub(), 30, 2);

        self::assertTrue($guard->markUsed('user-wide', 100, 1709800000));
        self::assertFalse($guard->markUsed('user-wide', 100, 1709800149));
    }

    #[Test]
    public function same_time_step_different_identity_is_allowed(): void
    {
        self::assertTrue($this->guard->markUsed('user-001', 100, 1709800000));
        self::assertTrue($this->guard->markUsed('user-002', 100, 1709800000));
    }

    #[Test]
    public function different_time_step_same_identity_is_allowed(): void
    {
        self::assertTrue($this->guard->markUsed('user-001', 100, 1709800000));
        self::assertTrue($this->guard->markUsed('user-001', 101, 1709800030));
    }

    #[Test]
    public function expired_entries_are_pruned(): void
    {
        // Insert an old entry (timestamp 1000, period 30, window 1: retention 120s)
        $this->guard->markUsed('user-001', 10, 1000);

        // Same time step must always be rejected (primary key prevents re-insert)
        $result = $this->guard->markUsed('user-001', 10, 1000);
        self::assertFalse($result, 'Same time step must always be rejected even if old');

        // A new time step after the old one expires should work
        self::assertTrue($this->guard->markUsed('user-001', 999, 2000));
    }

    #[Test]
    public function prune_removes_entries_older_than_the_retention_window(): void
    {
        // Directly insert an old entry to verify pruning behavior
        $this->pdo->prepare(
            'INSERT INTO auth_totp_replay_guard (user_id, time_step, used_at) VALUES (?, ?, ?)',
        )->execute(['user-prune-test', 1, '2020-01-01 00:00:00']);

        // Count before prune
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM auth_totp_replay_guard');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn());

        // Trigger prune by calling markUsed many times to hit the 1/20 probability
        $baseTime = 1709800000;
        for ($i = 0; $i < 100; $i++) {
            $this->guard->markUsed('user-prune-test', 5000 + $i, $baseTime + $i);
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM auth_totp_replay_guard WHERE user_id = 'user-prune-test' AND time_step = 1",
        );
        $stmt->execute();
        $remaining = (int) $stmt->fetchColumn();

        self::assertSame(0, $remaining, 'Entries past the retention window should be pruned');
    }

    /**
     * The audit's working exploit: five unrelated logins retired the victim's
     * blocking row and the attacker's first replay was accepted. Pruning is now
     * scoped to the submitting user, so ambient traffic cannot reach that row.
     */
    #[Test]
    public function other_users_traffic_cannot_evict_a_blocking_record(): void
    {
        self::assertTrue($this->guard->markUsed('victim', 100, 1709800000));

        for ($i = 0; $i < 500; $i++) {
            $this->guard->markUsed('bystander-' . $i, 5000 + $i, 1709800000 + $i);
        }

        self::assertFalse($this->guard->markUsed('victim', 100, 1709800089));
    }

    #[Test]
    public function many_sequential_inserts_do_not_cause_errors(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $result = $this->guard->markUsed('user-bulk', $i, 1709800000 + $i);
            self::assertTrue($result);
        }

        // Time step 25 was used at 1709800025 and the replay lands 25 s later,
        // well inside the retention window, so no prune can legitimately evict it.
        self::assertFalse($this->guard->markUsed('user-bulk', 25, 1709800050));
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
                try {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($bindings);

                    return $stmt->rowCount();
                } catch (PDOException $e) {
                    throw DatabaseException::queryFailed($sql, $e);
                }
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
