<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding\Contract;

use Pulsar\Api\Api;
use Pulsar\Routing\Binding\ResolutionContext;

/**
 * Port for resolving domain models from route parameters.
 *
 * Implementations live in the persistence layer (e.g. Eloquent, Doctrine).
 * The routing module depends only on this interface, never on ORM internals.
 */
#[Api(since: '1.0.0-rc.11')]
interface ModelResolverPort
{
    /**
     * Resolve a model by its key.
     *
     * @param class-string $modelClass
     */
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object;

    /**
     * Resolve a scoped model via its parent relationship.
     *
     * @param class-string $modelClass
     */
    public function resolveScoped(string $modelClass, string $keyName, string|int $keyValue, object $parent, string $relation, ResolutionContext $context): ?object;
}
