<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use NoDiscard;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;

use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_replace;

/**
 * The kernel's ordered chain of {@see HandlerArgumentResolverInterface}.
 *
 * Mutable by design (wirings and extensions append to it during boot) and
 * therefore snapshotted at boot() / restored at shutdown() exactly like the
 * router and the middleware pipeline: without that, a worker that re-boots
 * would stack a second copy of every resolver and resolve each bound model
 * twice.
 *
 * Ordered, but only ordinary claims are decided by the order. A
 * {@see SealedArgument} is decided by the claim — see
 * {@see self::resolveArguments()}, which is where both rules live and where the
 * reason the framework cannot rely on the order is written down.
 *
 * ## Appending is open; replacing is not
 *
 * {@see add()} is the contributed surface and stays open to anything holding
 * this object: an appended resolver is judged by the merge rules, and a seal
 * already on the chain refuses to be displaced by it.
 *
 * Replacing the whole list is a different power, because a resolver that is no
 * longer on the chain makes no claim at all — sealed or otherwise. It used to be
 * an unguarded public method, on an object this framework publishes in the
 * container, so anything that could reach the container could drop the
 * bound-model resolver and let its own claim win the parameter whose entity type
 * hint is what makes the route read as authorized. The capability still exists,
 * because a re-booting worker needs it, but it now lives in a single
 * {@see ArgumentResolverLifecycle} handed to the first caller of
 * {@see issueLifecycle()} — the {@see \Pulsar\Core\Kernel} constructor, which
 * runs before any wiring, extension or route file. Every later request for it is
 * refused.
 */
#[Internal(reason: 'Concrete chain owned by the Kernel; contribute through ArgumentResolverRegistryInterface')]
final class ArgumentResolverChain implements ArgumentResolverRegistryInterface
{
    /**
     * Read publicly so the kernel can take the pre-change code path with a
     * single `=== []` test when nothing is registered — no method call, no
     * allocation, on the hot path of every application that uses no resolver.
     *
     * @var list<HandlerArgumentResolverInterface>
     */
    public private(set) array $resolvers = [];

    /**
     * The one lifecycle handle this chain will ever issue, once it has.
     *
     * Kept so the second request can be refused. Nothing reads it back out:
     * the handle is the capability, and this reference only records that it is
     * spoken for.
     */
    private ?ArgumentResolverLifecycle $lifecycle = null;

    #[Override]
    public function add(HandlerArgumentResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Merge every resolver's claims into the map the kernel fills parameters from.
     *
     * EVERY resolver is asked before the caller draws any conclusion. The
     * kernel's decision about which parameters it can fill is taken against
     * this merged map, never against one resolver's view: a resolver sees only
     * the signature, the request and the route parameters, so it cannot know
     * that the parameter it just declined is supplied by the resolver after it.
     *
     * ## Two precedence rules, and only one of them involves order
     *
     * An UNSEALED claim follows first-claim-wins. The `+` union is what enforces
     * it: it keeps the left-hand value for any key present on both sides, and it
     * compares by key presence rather than by value, which is what preserves a
     * claim of null — a null claim is a claim, distinct from no claim at all,
     * and a `??`-style merge would lose it.
     *
     * A {@see SealedArgument} ignores order entirely. It overwrites an unsealed
     * claim made before it and blocks every unsealed claim made after it, so the
     * value reaches the handler whether its resolver was registered first, last
     * or in the middle. That matters because registration order is not something
     * the framework can arrange in its own favour:
     * {@see \Pulsar\Core\Wiring\ModelBindingWiring} registers the bound-model
     * resolver from {@see \Pulsar\Core\Boot\DeferredComposition}, which the
     * kernel drains at the END of boot — after extension `register()`, after
     * extension `boot()`, after the project route files. Under first-claim-wins
     * the framework resolver is therefore always LAST and always loses, and an
     * application resolver claiming a bound parameter name silently substituted
     * its own object for one an authorization hook had approved.
     *
     * Two seals on one name are refused rather than ranked; see
     * {@see ConflictingSealedArgumentException} for why that is the only answer
     * that does not smuggle registration order back in.
     *
     * ## Why this is written with array functions rather than a value loop
     *
     * A claim's value is `mixed` by contract, and binding one to a variable is
     * exactly the assignment the analysers forbid at this strictness. Filtering,
     * diffing and replacing by KEY keeps every mixed value inside an array the
     * whole way through, so the merge is checkable rather than suppressed. The
     * common case still costs one comparison: a resolver with nothing to supply
     * returns `[]` and is skipped before any of it runs.
     *
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     *
     * @throws ConflictingSealedArgumentException If two resolvers seal the same parameter.
     */
    #[NoDiscard]
    public function resolveArguments(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        $claimed = [];

        /** @var array<string, true> $sealed Names already sealed, by any resolver so far. */
        $sealed = [];

        foreach ($this->resolvers as $resolver) {
            $claims = $resolver->resolve($signature, $request, $routeParameters);

            if ($claims === []) {
                continue;
            }

            /** @var array<string, SealedArgument> $seals */
            $seals = array_filter($claims, static fn(mixed $value): bool => $value instanceof SealedArgument);

            if ($seals !== []) {
                $contested = array_intersect_key($seals, $sealed);

                if ($contested !== []) {
                    throw ConflictingSealedArgumentException::alreadySealed(
                        array_keys($contested),
                        $signature,
                        $resolver::class,
                    );
                }

                $sealed += array_fill_keys(array_keys($seals), true);

                // array_replace, not `+`: a seal must overwrite an unsealed claim
                // an earlier resolver already made. It preserves the key order of
                // $claimed and appends only genuinely new names, so a seal cannot
                // reshuffle the map either.
                $claimed = array_replace(
                    $claimed,
                    array_map(static fn(SealedArgument $seal): mixed => $seal->value, $seals),
                );
            }

            // Everything this resolver did not seal, minus every name any
            // resolver has sealed. `+` then applies first-claim-wins to what is
            // left, which is the pre-existing rule for uncontested and unsealed
            // names alike.
            $claimed += array_diff_key($claims, $sealed);
        }

        return $claimed;
    }

    /**
     * Hand out the capability to capture and restore this chain, once.
     *
     * The kernel takes it in its constructor and keeps it for the process
     * lifetime, so by the time a wiring, an extension or a route file runs there
     * is nothing left to issue. That is the whole guard: the boot/shutdown
     * capability is not a method anyone holding the chain may call, it is an
     * object exactly one caller was given, and the caller that gets it is the one
     * that constructs the chain in the first place.
     *
     * The closures below are what make the handle work while
     * {@see $resolvers} stays `private(set)`: they are declared inside this
     * class, so they carry its scope and may write the property that no caller
     * can. Neither closure is reachable from the returned object other than by
     * calling {@see ArgumentResolverLifecycle::snapshot()} and
     * {@see ArgumentResolverLifecycle::restore()}.
     *
     * @throws ArgumentResolverLifecycleException If a handle was already issued.
     */
    #[NoDiscard]
    public function issueLifecycle(): ArgumentResolverLifecycle
    {
        if ($this->lifecycle !== null) {
            throw ArgumentResolverLifecycleException::alreadyIssued();
        }

        return $this->lifecycle = new ArgumentResolverLifecycle(
            capture: fn(): array => $this->resolvers,
            apply: function (array $snapshot): void {
                $this->resolvers = $snapshot;
            },
        );
    }
}
