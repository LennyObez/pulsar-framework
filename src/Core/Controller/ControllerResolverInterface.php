<?php

declare(strict_types=1);

namespace Pulsar\Core\Controller;

use Pulsar\Api\Api;
use Pulsar\Routing\RoutingException;

/**
 * Resolves a routed controller class to an instance.
 *
 * Extracting this from the Kernel makes controller resolution an explicit,
 * swappable strategy: the default {@see ReflectionControllerResolver} prefers
 * container bindings and autowires by reflection only as a fallback, while a
 * production build may substitute a fully-compiled resolver with no reflection
 * on the hot path.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
interface ControllerResolverInterface
{
    /**
     * Resolve a controller class to a ready-to-invoke instance.
     *
     * @param class-string $class
     *
     * @throws RoutingException If the class is missing, not instantiable, or has
     *                          unsatisfiable constructor dependencies.
     */
    public function resolve(string $class): object;
}
