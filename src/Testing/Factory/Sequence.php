<?php

declare(strict_types=1);

namespace Pulsar\Testing\Factory;

use Closure;
use Pulsar\Api\Api;

use function count;

/**
 * Generates sequential values across factory calls.
 *
 * Usage:
 *   $sequence = new Sequence(fn (int $i) => sprintf('user-%d@test.com', $i));
 *   $sequence();  // "user-0@test.com"
 *   $sequence();  // "user-1@test.com"
 * @api
 */
#[Api(since: '1.0.0')]
final class Sequence
{
    /** @var Closure(int): mixed */
    private Closure $generator;

    private int $index = 0;

    /**
     * @param Closure(int): mixed $generator Function receiving the current index
     */
    public function __construct(Closure $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Create a sequence that cycles through the given values.
     *
     * @param list<mixed> $values
     */
    public static function cycle(array $values): self
    {
        return new self(static fn(int $i): mixed => $values[$i % count($values)]);
    }

    /**
     * Get the next value in the sequence.
     */
    public function __invoke(): mixed
    {
        return ($this->generator)($this->index++);
    }

    /**
     * Reset the sequence counter.
     */
    public function reset(): void
    {
        $this->index = 0;
    }
}
