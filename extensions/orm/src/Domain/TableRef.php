<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Table reference with optional alias.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TableRef
{
    public function __construct(
        public string $table,
        public ?string $alias = null,
    ) {}

    #[NoDiscard]
    public static function of(string $table, ?string $alias = null): self
    {
        IdentifierValidator::validate($table);
        if ($alias !== null) {
            IdentifierValidator::validate($alias);
        }

        return new self($table, $alias);
    }

    /**
     * Get the effective alias (alias if set, table name otherwise).
     */
    public function effectiveAlias(): string
    {
        return $this->alias ?? $this->table;
    }

    /**
     * @param callable(string): string $quoteIdentifier
     */
    public function toSql(callable $quoteIdentifier): string
    {
        $quoted = $quoteIdentifier($this->table);
        if ($this->alias !== null && $this->alias !== $this->table) {
            return sprintf('%s AS %s', $quoted, $quoteIdentifier($this->alias));
        }

        return $quoted;
    }
}
