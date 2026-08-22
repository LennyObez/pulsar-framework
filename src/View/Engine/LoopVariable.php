<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use Pulsar\Api\Api;

/**
 * Iteration metadata available as `$loop` inside @foreach blocks.
 *
 * Properties are set at the START of each iteration so they are
 * accurate for the current iteration's template body.
 *
 * Available properties:
 *   ->index      (int)  Zero-based index (0, 1, 2, ...)
 *   ->iteration  (int)  One-based count (1, 2, 3, ...)
 *   ->remaining  (int)  Items remaining after the current one
 *   ->count      (int)  Total item count
 *   ->first      (bool) True on the first iteration
 *   ->last       (bool) True on the last iteration
 *   ->even       (bool) True when index is even (0, 2, 4, ...)
 *   ->odd        (bool) True when index is odd (1, 3, 5, ...)
 *   ->depth      (int)  Nesting depth (1 for outermost)
 *   ->parent     (?self) Parent loop for nested @foreach
 * @api
 */
#[Api(since: '1.0.0')]
final class LoopVariable
{
    public int $index = -1;
    public int $iteration = 0;
    public int $remaining;
    public int $count;
    public bool $first = true;
    public bool $last = false;
    public bool $even = true;
    public bool $odd = false;
    public int $depth;
    public ?self $parent;

    public function __construct(int $count, int $depth = 1, ?self $parent = null)
    {
        $this->count = $count;
        $this->remaining = $count;
        $this->depth = $depth;
        $this->parent = $parent;
    }

    /**
     * Advance to the next iteration. Must be called at the START of each
     * iteration body so that all properties reflect the current item.
     */
    public function step(): void
    {
        $this->index++;
        $this->iteration = $this->index + 1;
        $this->first = $this->index === 0;
        $this->last = $this->iteration === $this->count;
        $this->remaining = $this->count - $this->iteration;
        $this->even = $this->index % 2 === 0;
        $this->odd = !$this->even;
    }
}
