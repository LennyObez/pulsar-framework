<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Closure;
use NoDiscard;
use Pulsar\Api\Internal;

/**
 * The only handle that can capture and restore an {@see ArgumentResolverChain}.
 *
 * A re-booting worker must not stack a second copy of every resolver, so the
 * chain has to be restorable to its pre-boot baseline exactly like the router
 * and the middleware pipeline. That capability used to be a public method on the
 * chain itself — {@see ArgumentResolverChain} is published in the container, so
 * ANY code that could reach the container could hand the chain a list of its own
 * resolvers and replace every registered one, the sealed bound-model resolver
 * included. Replacing the chain is a stronger power than registering a resolver:
 * a registration can be refused a contested name by a seal, a replacement
 * removes the seal.
 *
 * So the capability is a capability. The chain hands out ONE of these, to its
 * first caller — the {@see \Pulsar\Core\Kernel} constructor, which runs before
 * any wiring, any extension and any route file — and refuses every later
 * request. Whoever holds this object can restore the chain; whoever holds only
 * the chain can append a resolver and read the list, and nothing else.
 *
 * This constructor is public and that is not a hole: the closures decide which
 * chain is affected, and the only closures that reach the kernel's chain are the
 * ones {@see ArgumentResolverChain::issueLifecycle()} builds inside it. An
 * attacker constructing a lifecycle of their own gets a handle to whatever they
 * put in it, which is nothing the framework consults.
 */
#[Internal(reason: 'Boot/shutdown handle for the kernel argument-resolver chain; issued by ArgumentResolverChain::issueLifecycle()')]
final readonly class ArgumentResolverLifecycle
{
    /**
     * @param Closure(): list<HandlerArgumentResolverInterface>     $capture Reads the chain's current resolvers.
     * @param Closure(list<HandlerArgumentResolverInterface>): void $apply   Replaces the chain's resolvers wholesale.
     */
    public function __construct(
        private Closure $capture,
        private Closure $apply,
    ) {}

    /**
     * The chain's resolvers as they stand, to be restored later.
     *
     * @return list<HandlerArgumentResolverInterface>
     */
    #[NoDiscard]
    public function snapshot(): array
    {
        return ($this->capture)();
    }

    /**
     * Put the chain back to a captured baseline.
     *
     * @param list<HandlerArgumentResolverInterface> $snapshot
     */
    public function restore(array $snapshot): void
    {
        ($this->apply)($snapshot);
    }
}
