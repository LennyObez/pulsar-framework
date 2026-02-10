<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LockMode;

use function implode;
use function sprintf;

/**
 * MariaDB SQL dialect (mostly MySQL-compatible with minor differences).
 */
#[Internal]
final class MariaDbDialect implements DialectInterface
{
    #[Override]
    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
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
            LockMode::ForShare => ' LOCK IN SHARE MODE',
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
        $updates = [];
        foreach ($updateColumns as $col) {
            $updates[] = sprintf('%s = VALUES(%s)', $this->quoteIdentifier($col), $this->quoteIdentifier($col));
        }

        return $insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    #[Override]
    public function name(): string
    {
        return 'mariadb';
    }
}
