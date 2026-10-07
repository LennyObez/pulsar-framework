<?php

declare(strict_types=1);

namespace Pulsar\Cache;

use Pulsar\Api\Internal;
use Pulsar\Routing\Binding\BindingScope;

/**
 * Cache-safe representation of a `Router::model()` declaration.
 *
 * Mirrors `Pulsar\Routing\Binding\ExplicitBinding` field for field. The mirror
 * exists for the same reason {@see CachedRoute} does, stated the other way
 * round: {@see \Pulsar\Core\Boot\CachedRouteReconstructor} keeps the Router from
 * importing Cache-internal types, and this keeps Cache from importing
 * Routing-internal ones. `ExplicitBinding` is `#[Internal]` — an application
 * declares a binding by calling `Router::model()`, never by constructing one —
 * so a cache DTO typed against it would put a private type of one module in the
 * signature of another module's `#[Api]` interface.
 *
 * {@see BindingScope} crosses the boundary as itself because it is `#[Api]`: it
 * is the vocabulary an application writes in a route file, and mirroring a
 * closed enum would create a second closed set that could drift from the first.
 *
 * Nothing here validates. The pairing of scope and relation is `ExplicitBinding`'s
 * invariant and is enforced where that type is built — on the way in by
 * `Router::model()`, on the way back out by
 * {@see \Pulsar\Core\Boot\CachedRouteReconstructor::reconstructBindings()}. A
 * second copy of the rule here would be a second rule.
 */
#[Internal]
final readonly class CachedBinding
{
    /**
     * @param string $parameter Route parameter name the declaration is keyed by
     * @param class-string $modelClass Model the parameter resolves to
     * @param class-string|null $resolverClass Per-parameter resolver, when one was named
     * @param BindingScope $scope What the declaration says about containment
     * @param string|null $parentRelation Relation to resolve through; set only for a contained binding
     */
    public function __construct(
        public string $parameter,
        public string $modelClass,
        public ?string $resolverClass,
        public BindingScope $scope,
        public ?string $parentRelation,
    ) {}
}
