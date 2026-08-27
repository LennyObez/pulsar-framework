<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;

/**
 * Boot-order seam for composition decisions that depend on what extensions and
 * route files registered.
 *
 * Wirings run early in boot(); extensions register and boot later, and project
 * route files load later still. A wiring that gates on
 * `$container->has(SomePort::class)` inside `wire()` therefore reads a container
 * that no extension has touched yet — the answer is always "no", and the feature
 * it guards silently never composes. Likewise, state a route file fills (an
 * explicit binding list, a named-route table) is empty when read from `wire()`.
 *
 * A wiring registers the decision here instead and the kernel drains the queue
 * at the END of boot, once extension register, extension boot and project route
 * loading have all completed. The gate is then evaluated against the final
 * container.
 *
 * Absence is silent by design: a callback whose binding was never registered
 * simply does not run. That is what lets an optional capability (an ORM adapter,
 * a search backend) compose itself when present and cost one
 * {@see ContainerInterface::has()} call when not.
 */
#[Internal(reason: 'Boot-order seam: decisions that depend on what extensions registered')]
final class DeferredComposition
{
    /** @var list<array{0: string, 1: Closure(ContainerInterface): void}> */
    private array $pending = [];

    /**
     * Run $apply at the end of boot, only if $bindingId is bound by then.
     * Absence is silent: the closure simply never runs.
     *
     * `Container::has()` answers on explicit registrations only — a definition,
     * an instance, or a deferred provider — never an autowire guess, so the gate
     * cannot produce a false positive on an interface nobody bound.
     *
     * @param Closure(ContainerInterface): void $apply
     */
    public function whenBound(string $bindingId, Closure $apply): void
    {
        $this->pending[] = [$bindingId, $apply];
    }

    /**
     * Drain the queue.
     *
     * Draining (rather than marking each entry done) is what makes a worker
     * re-boot correct: shutdown() leaves nothing pending, and the next boot()
     * re-runs every wiring, which re-registers its callback against the
     * rebuilt container.
     */
    public function apply(ContainerInterface $container): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$bindingId, $callback]) {
            if ($container->has($bindingId)) {
                $callback($container);
            }
        }
    }
}
