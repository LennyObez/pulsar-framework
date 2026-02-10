<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use function implode;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LockMode;

use function sprintf;

/**
 * PostgreSQL SQL dialect.
 */
#[Internal]
final readonly class PostgreSqlDialect implements DialectInterface
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
        return match ($mode) {
            LockMode::None => '',
            LockMode::ForUpdate => ' FOR UPDATE',
            LockMode::ForShare => ' FOR SHARE',
        };
    }

    #[Override]
    public function currentTimestamp(): string
    {
        return 'NOW()';
    }

    #[Override]
    public function compileBooleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
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
            $updates[] = sprintf('%s = EXCLUDED.%s', $quoted, $quoted);
        }

        return $insertSql . sprintf(' ON CONFLICT (%s) DO UPDATE SET ', $conflict) . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'pgsql';
    }
}
