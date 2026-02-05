<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LockMode;

/**
 * SQL dialect abstraction for cross-database portability.
 */
#[Internal]
interface DialectInterface
{
    /**
     * Quote a table or column identifier for this dialect.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Compile a LIMIT/OFFSET clause.
     */
    public function compileLimitOffset(?int $limit, ?int $offset): string;

    /**
     * Compile a lock clause (FOR UPDATE, FOR SHARE).
     */
    public function compileLock(LockMode $mode): string;

    /**
     * Get the current timestamp SQL expression.
     */
    public function currentTimestamp(): string;

    /**
     * Compile a boolean literal.
     */
    public function compileBooleanLiteral(bool $value): string;

    /**
     * Whether this dialect supports RETURNING clause on INSERT.
     */
    public function supportsReturning(): bool;

    /**
     * Compile an upsert (INSERT ... ON CONFLICT / ON DUPLICATE KEY) clause.
     *
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     */
    public function compileUpsert(string $insertSql, array $conflictColumns, array $updateColumns): string;

    /**
     * Get the name of this dialect.
     */
    public function name(): string;
}
