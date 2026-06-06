<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Throwable;

use function random_int;
use function time;

/**
 * Database-backed cache driver.
 *
 * Stores cache entries in a `cache_entries` table with pool isolation.
 * Supports upsert semantics for all supported database drivers and
 * performs probabilistic garbage collection of expired rows.
 */
#[Internal]
final class DatabaseDriver extends AbstractCacheDriver
{
    private bool $tableCreated = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $pool = 'default',
    ) {}

    public function get(string $key): ?string
    {
        $this->ensureTable();
        $this->maybeGarbageCollect();

        $result = $this->connection->query(
            'SELECT value, expires_at FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key',
            ['pool' => $this->pool, 'cache_key' => $key],
        );

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        $expiresAt = $row->getNullableInt('expires_at');

        if ($expiresAt !== null && $expiresAt <= time()) {
            $this->delete($key);

            return null;
        }

        return $row->getString('value');
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        $this->ensureTable();

        $ttl = $this->normalizeTtl($ttlSeconds);

        if ($this->isExpiredTtl($ttl)) {
            $this->delete($key);

            return true;
        }

        $expiresAt = $ttl !== null ? time() + $ttl : null;

        $sql = match ($this->connection->driver()) {
            Driver::SQLite => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON CONFLICT(pool, cache_key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
            Driver::MySQL => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)',
            Driver::PostgreSQL => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON CONFLICT (pool, cache_key) DO UPDATE SET value = EXCLUDED.value, expires_at = EXCLUDED.expires_at',
        };

        try {
            $this->connection->execute($sql, [
                'pool' => $this->pool,
                'cache_key' => $key,
                'value' => $value,
                'expires_at' => $expiresAt,
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function delete(string $key): bool
    {
        $this->ensureTable();

        try {
            $this->connection->execute(
                'DELETE FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key',
                ['pool' => $this->pool, 'cache_key' => $key],
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->ensureTable();
        $this->maybeGarbageCollect();

        $result = $this->connection->query(
            'SELECT 1 FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key '
            . 'AND (expires_at IS NULL OR expires_at > :now)',
            ['pool' => $this->pool, 'cache_key' => $key, 'now' => time()],
        );

        return $result->first() !== null;
    }

    public function clear(): bool
    {
        $this->ensureTable();

        try {
            $this->connection->execute(
                'DELETE FROM cache_entries WHERE pool = :pool',
                ['pool' => $this->pool],
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function increment(string $key, int $step = 1): int|false
    {
        $this->ensureTable();

        try {
            return $this->connection->transaction(function (ConnectionInterface $conn) use ($key, $step): int {
                // Delete expired entry first
                $conn->execute(
                    'DELETE FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key '
                    . 'AND expires_at IS NOT NULL AND expires_at <= :now',
                    ['pool' => $this->pool, 'cache_key' => $key, 'now' => time()],
                );

                // Atomic in-place UPDATE avoids SELECT+UPDATE lost-update races
                $affected = $conn->execute(
                    'UPDATE cache_entries SET value = CAST(CAST(value AS INTEGER) + :step AS TEXT) '
                    . 'WHERE pool = :pool AND cache_key = :cache_key '
                    . 'AND (expires_at IS NULL OR expires_at > :now)',
                    ['step' => $step, 'pool' => $this->pool, 'cache_key' => $key, 'now' => time()],
                );

                if ($affected > 0) {
                    $result = $conn->query(
                        'SELECT value FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key',
                        ['pool' => $this->pool, 'cache_key' => $key],
                    );

                    return (int) ($result->first()?->getString('value') ?? '0');
                }

                // Row doesn't exist: insert initial value
                $conn->execute(
                    'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, NULL)',
                    ['pool' => $this->pool, 'cache_key' => $key, 'value' => (string) $step],
                );

                return $step;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
    }

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities(
            supportsTagsStrict: true,
            supportsLocksFencing: true,
            supportsBinary: true,
            supportsAtomicIncrement: true,
        );
    }

    public function name(): string
    {
        return 'database';
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS cache_entries ('
            . 'pool VARCHAR(255) NOT NULL, '
            . 'cache_key VARCHAR(255) NOT NULL, '
            . 'value BLOB NOT NULL, '
            . 'expires_at INTEGER NULL, '
            . 'PRIMARY KEY (pool, cache_key)'
            . ')',
        );

        $this->connection->execute(
            'CREATE INDEX IF NOT EXISTS idx_cache_entries_expires_at ON cache_entries (expires_at)',
        );

        $this->tableCreated = true;
    }

    private function maybeGarbageCollect(): void
    {
        try {
            if (random_int(1, 100) !== 1) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $this->connection->execute(
                'DELETE FROM cache_entries WHERE expires_at IS NOT NULL AND expires_at <= :now',
                ['now' => time()],
            );
        } catch (Throwable) {
            // GC is best-effort
        }
    }
}
