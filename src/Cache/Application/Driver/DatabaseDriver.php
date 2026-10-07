<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Driver;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Param;
use Pulsar\Database\Schema\IndexOperations;
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

    /** Dialect-specific upsert statement, resolved once from the connection driver. */
    private readonly string $upsertSql;

    /** Dialect-specific atomic-increment statement, resolved once from the connection driver. */
    private readonly string $incrementSql;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $pool = 'default',
    ) {
        $this->upsertSql = $this->buildUpsertSql($connection->driver());
        $this->incrementSql = $this->buildIncrementSql($connection->driver());
    }

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

        try {
            $this->connection->execute($this->upsertSql, [
                'pool' => $this->pool,
                'cache_key' => $key,
                // Bound as a binary parameter so it lands in the BLOB/BYTEA value
                // column on every driver — a plain string bind cannot be assigned
                // to a PostgreSQL BYTEA column.
                'value' => Param::binary($value),
                'expires_at' => $expiresAt,
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

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

                // Atomic in-place UPDATE avoids SELECT+UPDATE lost-update races.
                // The integer cast is dialect-specific (a PostgreSQL BYTEA cannot
                // be cast straight to INTEGER), so the statement is resolved per
                // driver in buildIncrementSql().
                $affected = $conn->execute(
                    $this->incrementSql,
                    ['step' => $step, 'pool' => $this->pool, 'cache_key' => $key, 'now' => time()],
                );

                if ($affected > 0) {
                    $result = $conn->query(
                        'SELECT value FROM cache_entries WHERE pool = :pool AND cache_key = :cache_key',
                        ['pool' => $this->pool, 'cache_key' => $key],
                    );

                    return (int) ($result->first()?->getString('value') ?? '0');
                }

                // Row doesn't exist: insert initial value (binary-bound for BYTEA)
                $conn->execute(
                    'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, NULL)',
                    ['pool' => $this->pool, 'cache_key' => $key, 'value' => Param::binary((string) $step)],
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

    /**
     * Build the dialect-specific upsert statement for the connection driver.
     *
     * The connection's driver type is invariant for the lifetime of this
     * object, so the statement is resolved once at construction rather than
     * on every set() call.
     */
    private function buildUpsertSql(Driver $driver): string
    {
        return match ($driver) {
            Driver::SQLite => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON CONFLICT(pool, cache_key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
            Driver::MySQL => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)',
            Driver::PostgreSQL => 'INSERT INTO cache_entries (pool, cache_key, value, expires_at) VALUES (:pool, :cache_key, :value, :expires_at)'
                . ' ON CONFLICT (pool, cache_key) DO UPDATE SET value = EXCLUDED.value, expires_at = EXCLUDED.expires_at',
        };
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->connection->execute($this->buildCreateTableSql($this->connection->driver()));

        // Not `CREATE INDEX IF NOT EXISTS`: MySQL rejects that clause as a syntax error
        // rather than ignoring it, so this method — which runs on the first cache write,
        // not in a migration — took the database cache driver out of service there.
        new IndexOperations($this->connection)
            ->ensure('cache_entries', 'idx_cache_entries_expires_at', ['expires_at']);

        $this->tableCreated = true;
    }

    /**
     * Build the dialect-specific CREATE TABLE statement. The value column holds
     * arbitrary (possibly binary) serialized cache payloads, so it must use the
     * driver's binary type: PostgreSQL has no BLOB type and uses BYTEA instead.
     */
    private function buildCreateTableSql(Driver $driver): string
    {
        $valueType = match ($driver) {
            Driver::PostgreSQL => 'BYTEA',
            Driver::MySQL, Driver::SQLite => 'BLOB',
        };

        return 'CREATE TABLE IF NOT EXISTS cache_entries ('
            . 'pool VARCHAR(255) NOT NULL, '
            . 'cache_key VARCHAR(255) NOT NULL, '
            . 'value ' . $valueType . ' NOT NULL, '
            . 'expires_at INTEGER NULL, '
            . 'PRIMARY KEY (pool, cache_key)'
            . ')';
    }

    /**
     * Build the dialect-specific atomic-increment UPDATE. The value column is a
     * binary type, so reading it back as an integer and writing the result back
     * differs per driver:
     *  - SQLite casts the BLOB straight through CAST(... AS INTEGER);
     *  - MySQL casts to SIGNED / CHAR (it rejects CAST(... AS INTEGER));
     *  - PostgreSQL must decode the BYTEA to text first (convert_from), do the
     *    arithmetic, then re-encode the result to BYTEA (convert_to) — a bare
     *    CAST(value AS INTEGER) is invalid on BYTEA.
     */
    private function buildIncrementSql(Driver $driver): string
    {
        $where = 'WHERE pool = :pool AND cache_key = :cache_key '
            . 'AND (expires_at IS NULL OR expires_at > :now)';

        return match ($driver) {
            Driver::SQLite => 'UPDATE cache_entries SET value = CAST(CAST(value AS INTEGER) + :step AS TEXT) ' . $where,
            Driver::MySQL => 'UPDATE cache_entries SET value = CAST(CAST(value AS SIGNED) + :step AS CHAR) ' . $where,
            Driver::PostgreSQL => 'UPDATE cache_entries SET value = '
                . "convert_to((convert_from(value, 'UTF8')::INTEGER + :step)::TEXT, 'UTF8') " . $where,
        };
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
