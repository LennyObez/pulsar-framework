<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Guard;

use Pulsar\Api\Api;

/**
 * Port for resolving guard instances from class names.
 *
 * Implementations typically delegate to the DI container. This port
 * decouples the engine from the container, keeping guard resolution
 * testable and explicit.
 * @api
 */
#[Api(since: '1.0.0')]
interface GuardResolverInterface
{
    /**
     * Resolve a guard instance from its class name.
     *
     * @param class-string $guardClass
     */
    public function resolve(string $guardClass): TransitionGuardInterface;
}
