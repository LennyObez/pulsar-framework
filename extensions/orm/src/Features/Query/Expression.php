<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Query;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Structured query expression with operator and bindings.
 *
 * Represents a single WHERE condition, HAVING clause, or similar
 * filter expression in compiled form.
 */
#[Api(since: '1.0.0')]
final readonly class Expression
{
    /**
     * @param string $sql The compiled SQL fragment (e.g., "t0.name = :p0")
     * @param array<string, mixed> $bindings Named parameter bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    /**
     * @param array<string, mixed> $bindings
     */
    #[NoDiscard]
    public static function of(string $sql, array $bindings = []): self
    {
        return new self($sql, $bindings);
    }
}
