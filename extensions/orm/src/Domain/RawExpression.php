<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Raw SQL expression that bypasses quoting.
 *
 * Use sparingly and never with user input. Primarily for database
 * functions (NOW(), COUNT(*), etc.) and computed expressions.
 */
#[Api(since: '1.0.0')]
final readonly class RawExpression
{
    /**
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    #[NoDiscard]
    public static function of(string $sql, array $bindings = []): self
    {
        return new self($sql, $bindings);
    }
}
