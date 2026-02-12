<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Lock;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Database\ConnectionInterface;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

use function bin2hex;
use function gethostname;
use function getmypid;
use function hash_equals;
use function microtime;
use function time;
use function usleep;

/**
 * Database-backed distributed lock.
 */
#[Internal]
final class DatabaseLock implements LockInterface
{
    private bool $tableCreated = false;

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
    {
        $this->ensureTable();

        $deadlineNs = hrtime(true) + ($timeoutMs * 1_000_000);

        $owner = (gethostname() ?: 'unknown') . ':' . (getmypid() ?: 0);

        do {
            $token = bin2hex($this->randomizer->getBytes(16));
            $expiresAt = time() + $ttlSeconds;

            // Atomically clean expired locks and attempt insertion within a
            // single transaction to prevent TOCTOU races where another process
            // could acquire between the DELETE and INSERT.
            try {
                $affected = $this->connection->transaction(function (ConnectionInterface $conn) use ($resource, $token, $owner, $expiresAt): int {
                    $conn->execute(
                        'DELETE FROM cache_locks WHERE resource = :resource AND expires_at <= :now',
                        ['resource' => $resource, 'now' => time()],
                    );

                    return $conn->execute(
                        'INSERT INTO cache_locks (resource, token, owner, expires_at) '
                        . 'SELECT :resource, :token, :owner, :expires_at '
                        . 'WHERE NOT EXISTS (SELECT 1 FROM cache_locks WHERE resource = :resource2)',
                        [
                            'resource' => $resource,
                            'token' => $token,
                            'owner' => $owner,
                            'expires_at' => $expiresAt,
                            'resource2' => $resource,
                        ],
                    );
                });
            } catch (Throwable) {
                $affected = 0;
            }

            if ($affected > 0) {
                return new LockHandle(
                    resource: $resource,
                    token: $token,
                    acquiredAt: microtime(true),
                    ttlSeconds: $ttlSeconds,
                );
            }

            if ($timeoutMs === 0) {
                throw LockAcquisitionException::timeout($resource, $timeoutMs);
            }

            usleep(10_000);
        } while (hrtime(true) < $deadlineNs);

        throw LockAcquisitionException::timeout($resource, $timeoutMs);
    }

    public function release(LockHandle $handle): bool
    {
        $this->ensureTable();

        // Verify token ownership with constant-time comparison before issuing the DELETE.
        $result = $this->connection->query(
            'SELECT token FROM cache_locks WHERE resource = :resource',
            ['resource' => $handle->resource],
        );

        $row = $result->first();

        if ($row === null || !hash_equals($row->getString('token'), $handle->token)) {
            return false;
        }

        $affected = $this->connection->execute(
            'DELETE FROM cache_locks WHERE resource = :resource AND token = :token',
            ['resource' => $handle->resource, 'token' => $handle->token],
        );

        return $affected > 0;
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
    {
        $this->ensureTable();

        // Verify token ownership with constant-time comparison before issuing the UPDATE.
        $result = $this->connection->query(
            'SELECT token FROM cache_locks WHERE resource = :resource',
            ['resource' => $handle->resource],
        );

        $row = $result->first();

        if ($row === null || !hash_equals($row->getString('token'), $handle->token)) {
            return false;
        }

        $expiresAt = time() + $ttlSeconds;

        $affected = $this->connection->execute(
            'UPDATE cache_locks SET expires_at = :expires_at WHERE resource = :resource AND token = :token',
            ['expires_at' => $expiresAt, 'resource' => $handle->resource, 'token' => $handle->token],
        );

        return $affected > 0;
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS cache_locks ('
            . 'resource VARCHAR(255) NOT NULL PRIMARY KEY, '
            . 'token VARCHAR(255) NOT NULL, '
            // Identifies the process holding the lock (hostname:pid) for debugging
            . 'owner VARCHAR(255) NOT NULL, '
            . 'expires_at INTEGER NOT NULL'
            . ')',
        );

        $this->connection->execute(
            'CREATE INDEX IF NOT EXISTS idx_cache_locks_expires_at ON cache_locks (expires_at)',
        );

        $this->tableCreated = true;
    }
}
