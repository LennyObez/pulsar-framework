<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;

use function array_key_exists;

/**
 * The service ids one extension registered through its own scope.
 *
 * Deny-by-default answers "may this extension resolve a service the HOST
 * bound?", and the answer for an unclassified id is no. It cannot be the answer
 * for an id the extension bound ITSELF: a service provider that registers
 * `TicketRepositoryInterface` and then resolves it is doing the one thing
 * providers exist to do, and the previous round of sandbox work denied it —
 * every bundled extension above Core stopped working at the first resolution of
 * its own binding.
 *
 * What makes this safe is not the ledger, it is where the entries come from.
 * An id lands here only by passing through {@see ScopedContainerProxy::bind()},
 * {@see ScopedContainerProxy::singleton()} or
 * {@see ScopedContainerProxy::instance()}, each of which asserts the
 * registration capability first and rebinds the concrete to a scope-bound
 * factory. So resolving an owned id returns something this scope built. The
 * ledger records a fact about construction; it does not grant a permission.
 *
 * Ownership is consulted AFTER the restriction map and the safe list, never
 * before ({@see ScopedContainerProxy::assertCanResolve()}). It can therefore
 * only rescue an id nothing else classified — it cannot buy a restricted
 * service, and it cannot buy the container.
 *
 * Mutable by design, and the only mutable thing in the scope: the proxy itself
 * is `readonly`, which is what stops in-process code from swapping its inner
 * container by reflection, and a `readonly` class cannot accumulate anything.
 * One instance per extension, held for the extension's whole lifetime — see
 * {@see \Pulsar\Extensibility\ExtensionBootstrap::scopeContainer()}, which
 * caches the scope per extension so a registration made in `register()` is
 * still owned in `boot()`.
 *
 * @internal Not part of the public API
 */
final class ScopeRegistrations
{
    /** @var array<string, true> */
    private array $ids = [];

    /**
     * Record an id this extension registered through its scope.
     */
    public function record(string $id): void
    {
        $this->ids[$id] = true;
    }

    /**
     * Whether this extension registered the id itself.
     */
    #[NoDiscard]
    public function owns(string $id): bool
    {
        return array_key_exists($id, $this->ids);
    }
}
