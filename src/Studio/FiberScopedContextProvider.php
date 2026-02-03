<?php

declare(strict_types=1);

namespace Pulsar\Studio;

use Fiber;
use Pulsar\Api\Internal;
use SplStack;
use stdClass;
use WeakMap;

/**
 * Fiber-safe correlation context provider using per-Fiber scope stacks.
 *
 * Each Fiber (and the root non-Fiber context) gets its own SplStack via WeakMap.
 * Interleaved Fiber execution never corrupts correlation IDs. When a Fiber
 * completes and is garbage-collected, its WeakMap entry is automatically reclaimed.
 */
#[Internal]
final class FiberScopedContextProvider implements CorrelationContextProviderInterface
{
    /**
     * Per-Fiber (or root) scope stacks.
     *
     * @var WeakMap<object, SplStack<CorrelationContext>>
     */
    private WeakMap $stacks;

    /** Stable key for the root (non-Fiber) execution context. */
    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, SplStack<CorrelationContext>> $stacks */
        $stacks = new WeakMap();
        $this->stacks = $stacks;
        $this->rootKey = new stdClass();
    }

    /**
     * Push a context onto the current Fiber's (or root) scope stack.
     * Returns a ContextScope guard that MUST be closed in a finally block.
     */
    public function enter(CorrelationContext $context): ContextScope
    {
        $this->stackFor($this->currentKey())->push($context);

        return new ContextScope(fn() => $this->leave());
    }

    /**
     * Returns the innermost active context for the current Fiber (or root),
     * or null if no scope is active.
     */
    public function current(): ?CorrelationContext
    {
        $key = $this->currentKey();

        /** @var SplStack<CorrelationContext>|null $stack */
        $stack = $this->stacks[$key] ?? null;

        if ($stack === null || $stack->isEmpty()) {
            return null;
        }

        return $stack->top();
    }

    private function leave(): void
    {
        $key = $this->currentKey();

        /** @var SplStack<CorrelationContext>|null $stack */
        $stack = $this->stacks[$key] ?? null;

        if ($stack !== null && !$stack->isEmpty()) {
            $stack->pop();
        }
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }

    /** @return SplStack<CorrelationContext> */
    private function stackFor(object $key): SplStack
    {
        /** @var SplStack<CorrelationContext>|null $existing */
        $existing = $this->stacks[$key] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        /** @var SplStack<CorrelationContext> $stack */
        $stack = new SplStack();
        $this->stacks[$key] = $stack;

        return $stack;
    }
}
