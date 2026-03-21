<?php

declare(strict_types=1);

namespace Pulsar\Database\QueryLog;

use NoDiscard;
use Pulsar\Api\Api;

use function array_slice;
use function array_sum;
use function count;
use function debug_backtrace;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * In-memory query logger for debugging and profiling.
 *
 * Records SQL queries with bindings, timing, and caller information.
 * Designed for development use: should not be enabled in production.
 * @api
 */
#[Api(since: '1.0.0')]
final class QueryLogger
{
    /** @var list<QueryLogEntry> */
    private array $entries = [];

    private bool $enabled = false;

    /**
     * Enable query logging.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable query logging.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    #[NoDiscard]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Log a query execution.
     *
     * @param array<string, mixed> $bindings
     */
    public function log(string $sql, array $bindings, float $durationMs): void
    {
        if (!$this->enabled) {
            return;
        }

        $caller = $this->resolveCaller();

        $this->entries[] = new QueryLogEntry(
            sql: $sql,
            bindings: $bindings,
            durationMs: $durationMs,
            callerFile: $caller['file'],
            callerLine: $caller['line'],
        );
    }

    /**
     * Get all logged entries.
     *
     * @return list<QueryLogEntry>
     */
    #[NoDiscard]
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Get the total number of logged queries.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Get total query time in milliseconds.
     */
    #[NoDiscard]
    public function totalTimeMs(): float
    {
        return array_sum(array_map(
            static fn(QueryLogEntry $e): float => $e->durationMs,
            $this->entries,
        ));
    }

    /**
     * Get the slowest queries.
     *
     * @return list<QueryLogEntry>
     */
    #[NoDiscard]
    public function slowest(int $limit = 10): array
    {
        $sorted = $this->entries;
        usort($sorted, static fn(QueryLogEntry $a, QueryLogEntry $b): int => $b->durationMs <=> $a->durationMs);

        return array_slice($sorted, 0, $limit);
    }

    /**
     * Clear all logged entries.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * @return array{file: string|null, line: int|null}
     */
    private function resolveCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        // Walk up the stack past internal database classes
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            // Skip frames from the database layer itself
            if (str_contains($file, 'Database' . DIRECTORY_SEPARATOR . 'Pdo')
                || str_contains($file, 'Database' . DIRECTORY_SEPARATOR . 'Pool')
                || str_contains($file, 'Database' . DIRECTORY_SEPARATOR . 'Monitor')
                || str_contains($file, 'Database' . DIRECTORY_SEPARATOR . 'QueryLog')
            ) {
                continue;
            }

            return [
                'file' => $file,
                'line' => $frame['line'] ?? null,
            ];
        }

        return ['file' => null, 'line' => null];
    }
}
