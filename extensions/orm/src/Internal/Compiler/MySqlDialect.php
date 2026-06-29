<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Internal\Compiler;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Domain\LockMode;

use function implode;
use function sprintf;
use function str_replace;

/**
 * MySQL/MariaDB SQL dialect.
 */
#[Internal]
final readonly class MySqlDialect implements DialectInterface
{
    #[Override]
    public function quoteIdentifier(string $identifier): string
    {
        // Escape the backtick delimiter by doubling it (and strip NUL
        // bytes). Without this, an attacker-influenced identifier — e.g.
        // a column name decoded from a tampered pagination cursor or a
        // request-controlled sort field — could embed a `` ` `` to break
        // out of the quoted identifier into arbitrary SQL.
        return '`' . str_replace(['`', "\0"], ['``', ''], $identifier) . '`';
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
        return false;
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
        return 'mysql';
    }
}
