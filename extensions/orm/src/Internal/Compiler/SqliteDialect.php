<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LockMode;

use function implode;
use function sprintf;

/**
 * SQLite SQL dialect.
 */
#[Internal]
final readonly class SqliteDialect implements DialectInterface
{
    #[Override]
    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    #[Override]
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        $sql = '';
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }
        if ($offset !== null && $offset > 0) {
            $sql .= sprintf(' OFFSET %d', $offset);
        }

        return $sql;
    }

    #[Override]
    public function compileLock(LockMode $mode): string
    {
        // SQLite does not support row-level locking
        return '';
    }

    #[Override]
    public function currentTimestamp(): string
    {
        return "datetime('now')";
    }

    #[Override]
    public function compileBooleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    #[Override]
    public function supportsReturning(): bool
    {
        return true;
    }

    #[Override]
    public function compileUpsert(string $insertSql, array $conflictColumns, array $updateColumns): string
    {
        $conflict = implode(', ', array_map([$this, 'quoteIdentifier'], $conflictColumns));
        $updates = [];
        foreach ($updateColumns as $col) {
            $quoted = $this->quoteIdentifier($col);
            $updates[] = sprintf('%s = excluded.%s', $quoted, $quoted);
        }

        return $insertSql . sprintf(' ON CONFLICT (%s) DO UPDATE SET ', $conflict) . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'sqlite';
    }
}
