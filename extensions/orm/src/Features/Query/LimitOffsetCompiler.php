<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Internal\Compiler\DialectInterface;

/**
 * Compiles LIMIT/OFFSET clauses via the dialect.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class LimitOffsetCompiler
{
    public function __construct(
        private DialectInterface $dialect,
    ) {}

    public function compile(?int $limit, ?int $offset): string
    {
        return $this->dialect->compileLimitOffset($limit, $offset);
    }
}
