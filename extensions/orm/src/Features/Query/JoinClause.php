<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;

use function sprintf;

/**
 * Compiled JOIN clause ready for SQL emission.
 */
#[Internal]
final readonly class JoinClause
{
    /**
     * @param string $type JOIN type (INNER, LEFT, RIGHT, CROSS)
     * @param string $table Quoted table + alias expression
     * @param string $condition Compiled ON condition
     * @param array<string, mixed> $bindings Any bindings from the ON clause
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $type,
        public string $table,
        public string $condition,
        public array $bindings = [],
    ) {}

    public function toSql(): string
    {
        return sprintf('%s JOIN %s ON %s', $this->type, $this->table, $this->condition);
    }
}
