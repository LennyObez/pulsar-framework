<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use PDO;
use PDOException;
use Pulsar\Api\Internal;
use Pulsar\Support\SqliteWalFactory;

use function max;
use function random_int;
use function time;

/**
 * Fixed-window rate limiter backed by SQLite with WAL journaling.
 *
 * Provides process-safe rate limiting suitable for multi-worker deployments
 * (e.g., PHP-FPM). Uses SQLite's UPSERT for atomic count increments and
 * WAL mode for improved read concurrency.
 *
 * Window semantics: fixed windows aligned to windowSeconds intervals.
 * All clients share the same window boundaries.
 */
#[Internal]
final readonly class SqliteRateLimiter implements RateLimiterInterface
{
    private PDO $db;

    public function __construct(
        private int $maxAttempts,
        private int $windowSeconds,
        string $storagePath,
    ) {
        $this->db = SqliteWalFactory::create($storagePath, <<<'SQL'
            CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                count INTEGER NOT NULL DEFAULT 0,
                window_start INTEGER NOT NULL
            )
            SQL);
    }

    public function hit(string $key): RateLimitResult
    {
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);

        $this->db->prepare(<<<'SQL'
            INSERT INTO rate_limits (key, count, window_start)
            VALUES (?, 1, ?)
            ON CONFLICT(key) DO UPDATE SET
                count = CASE
                    WHEN window_start < ? THEN 1
                    ELSE count + 1
                END,
                window_start = CASE
                    WHEN window_start < ? THEN ?
                    ELSE window_start
                END
            SQL)->execute([$key, $windowStart, $windowStart, $windowStart, $windowStart]);

        $stmt = $this->db->prepare('SELECT count, window_start FROM rate_limits WHERE key = ?');
        $stmt->execute([$key]);

        /** @var array{count: int, window_start: int}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $row !== false ? $row['count'] : 1;
        $rowWindowStart = $row !== false ? $row['window_start'] : $windowStart;

        // Probabilistic pruning (1% of requests)
        if (random_int(1, 100) === 1) {
            $this->prune($windowStart);
        }

        $allowed = $count <= $this->maxAttempts;
        $remaining = max(0, $this->maxAttempts - $count);
        $retryAfter = $allowed ? 0 : ($rowWindowStart + $this->windowSeconds - $now);

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->maxAttempts,
            remaining: $remaining,
            retryAfter: $retryAfter,
        );
    }

    public function attempts(string $key): int
    {
        $now = time();
        $windowStart = $now - ($now % $this->windowSeconds);

        $stmt = $this->db->prepare('SELECT count FROM rate_limits WHERE key = ? AND window_start >= ?');
        $stmt->execute([$key, $windowStart]);

        /** @var mixed $count */
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function reset(string $key): void
    {
        $this->db->prepare('DELETE FROM rate_limits WHERE key = ?')->execute([$key]);
    }

    private function prune(int $currentWindowStart): void
    {
        try {
            $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                ->execute([$currentWindowStart]);
        } catch (PDOException) {
            // Best-effort pruning: non-critical
        }
    }
}
