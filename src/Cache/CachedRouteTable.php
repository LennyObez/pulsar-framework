<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;

/**
 * Everything `routes.cache.bin` holds: the route table and the binding
 * declarations registered alongside it.
 *
 * ## Why the two are one payload
 *
 * They are only correct together. `Router::model()` runs from the project route
 * files, and a cached-route boot skips those files by design — so a cache that
 * carried routes without declarations would boot an application whose
 * {@see \Pulsar\Routing\Binding\BindingScope::Root} and
 * {@see \Pulsar\Routing\Binding\BindingScope::Contained} statements had
 * evaporated, while the route table they qualify was still served. A deliberate
 * unscoped nested child would revert to a scoped lookup and 404; a declared
 * relation would revert to the path's and refuse with a 500. Both are loud, and
 * both were unavoidable in an optimized deployment, which is how a fail-closed
 * design gets switched off.
 *
 * Splitting them across two files would have made "routes from this warm,
 * declarations from that one" representable. One serialized payload under one
 * manifest signature and one invalidation key makes it not.
 */
#[Internal]
final readonly class CachedRouteTable
{
    /**
     * @param list<CachedRoute> $routes Cache-safe routes; closure handlers are dropped by the writer
     * @param list<CachedBinding> $bindings Cache-safe declarations, in registration order
     */
    public function __construct(
        public array $routes,
        public array $bindings,
    ) {}
}
