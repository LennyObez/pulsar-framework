<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Statement;
use Pulsar\Database\Transaction;

use function ltrim;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Read-only database connection decorator for REPL safe mode.
 *
 * Allows SELECT, EXPLAIN, DESCRIBE, SHOW, and PRAGMA queries.
 * WITH (CTE) queries are allowed only when the final statement is read-only.
 * Blocks all mutations (INSERT, UPDATE, DELETE, DDL) and transactions.
 */
#[Internal]
final readonly class ReadOnlyConnection implements ConnectionInterface
{
    public function __construct(
        private ConnectionInterface $inner,
    ) {}

    /**
     * @throws ReplSafeModeException If the query is not read-only
     */
    #[Override]
    public function query(string $sql, array $bindings = []): Result
    {
        $this->assertReadOnly($sql);

        return $this->inner->query($sql, $bindings);
    }

    /**
     * @throws ReplSafeModeException Always: execute is blocked in safe mode
     */
    #[Override]
    public function execute(string $sql, array $bindings = []): int
    {
        throw ReplSafeModeException::operationBlocked('execute');
    }

    /**
     * Prepare a read-only statement.
     *
     * The SQL is validated before preparing. Only read-only queries are allowed.
     *
     * @throws ReplSafeModeException If the query is not read-only
     */
    #[Override]
    public function prepare(string $sql): Statement
    {
        $this->assertReadOnly($sql);

        return $this->inner->prepare($sql);
    }

    /**
     * @throws ReplSafeModeException Always: transactions are blocked in safe mode
     */
    #[Override]
    public function beginTransaction(): Transaction
    {
        throw ReplSafeModeException::operationBlocked('beginTransaction');
    }

    /**
     * @throws ReplSafeModeException Always: transactions are blocked in safe mode
     */
    #[Override]
    public function transaction(callable $callback): mixed
    {
        throw ReplSafeModeException::operationBlocked('transaction');
    }

    #[Override]
    public function lastInsertId(): string
    {
        return '0';
    }

    #[Override]
    public function driver(): Driver
    {
        return $this->inner->driver();
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    #[Override]
    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    /**
     * Validate that the SQL is a read-only query.
     *
     * @throws ReplSafeModeException If the query would mutate data
     */
    private function assertReadOnly(string $sql): void
    {
        // Strip leading whitespace and SQL comments
        $normalized = preg_replace('/^\s*(?:--[^\n]*\n|\/\*.*?\*\/\s*)*/s', '', $sql) ?? $sql;

        // Strip MySQL conditional comments (/*!... */ or /*!12345 ... */)
        $normalized = preg_replace('#/\*!\d*\s*(.*?)\*/#s', '$1', $normalized) ?? $normalized;
        $normalized = ltrim($normalized);
        $upper = strtoupper($normalized);

        // EXPLAIN [ANALYZE] [VERBOSE] must not wrap DML
        if (str_starts_with($upper, 'EXPLAIN')) {
            $remainder = preg_replace('/^EXPLAIN\s+(?:ANALYZE\s+)?(?:VERBOSE\s+)?/i', '', $upper) ?? $upper;

            if (str_starts_with($remainder, 'INSERT')
                || str_starts_with($remainder, 'UPDATE')
                || str_starts_with($remainder, 'DELETE')
                || str_starts_with($remainder, 'MERGE')
                || str_starts_with($remainder, 'CALL')) {
                throw ReplSafeModeException::writeQueryBlocked($sql);
            }

            return;
        }

        // SELECT INTO OUTFILE/DUMPFILE writes to filesystem
        if (str_starts_with($upper, 'SELECT')) {
            if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $upper)) {
                throw ReplSafeModeException::writeQueryBlocked($sql);
            }

            return;
        }

        if (str_starts_with($upper, 'DESCRIBE')
            || str_starts_with($upper, 'SHOW')
            || str_starts_with($upper, 'PRAGMA')) {
            return;
        }

        // WITH CTEs require deeper validation: the final statement must be read-only
        if (str_starts_with($upper, 'WITH') && $this->isWithQueryReadOnly($normalized)) {
            return;
        }

        throw ReplSafeModeException::writeQueryBlocked($sql);
    }

    /**
     * Validate that a WITH (CTE) query's final statement is read-only.
     *
     * Tracks parenthesis depth and string literals to find where CTE
     * definitions end, then validates the main statement keyword.
     */
    private function isWithQueryReadOnly(string $sql): bool
    {
        $depth = 0;
        $len = strlen($sql);
        $inString = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            // Track single-quoted string literals (SQL standard)
            if ($char === "'") {
                if ($inString) {
                    // Check for escaped quote ('')
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $i++; // Skip escaped quote

                        continue;
                    }

                    $inString = false;
                } else {
                    $inString = true;
                }

                continue;
            }

            // Skip parenthesis tracking inside string literals
            if ($inString) {
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    // After closing a top-level paren, check what follows
                    $after = ltrim(substr($sql, $i + 1));

                    if ($after === '' || $after[0] === ',') {
                        // End of SQL or another CTE definition: continue scanning
                        continue;
                    }

                    // This is the main statement; validate it's read-only
                    $afterUpper = strtoupper($after);

                    return str_starts_with($afterUpper, 'SELECT')
                        || str_starts_with($afterUpper, 'EXPLAIN')
                        || str_starts_with($afterUpper, 'DESCRIBE')
                        || str_starts_with($afterUpper, 'SHOW')
                        || str_starts_with($afterUpper, 'PRAGMA');
                }
            }
        }

        // No valid CTE structure found; deny by default
        return false;
    }
}
