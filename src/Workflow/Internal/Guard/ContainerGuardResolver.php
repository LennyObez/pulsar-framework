<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Guard;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Workflow\Exception\WorkflowException;
use Pulsar\Workflow\Guard\GuardResolverInterface;
use Pulsar\Workflow\Guard\TransitionGuardInterface;

/**
 * Container-backed adapter for the {@see GuardResolverInterface} port.
 *
 * Resolves transition guards through the DI container so guards can take
 * constructor dependencies, while the engine stays decoupled from the
 * container itself (the port exists precisely for that).
 */
#[Internal]
final readonly class ContainerGuardResolver implements GuardResolverInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function resolve(string $guardClass): TransitionGuardInterface
    {
        $guard = $this->container->get($guardClass);

        if (!$guard instanceof TransitionGuardInterface) {
            throw WorkflowException::invalidGuard($guardClass);
        }

        return $guard;
    }
}
