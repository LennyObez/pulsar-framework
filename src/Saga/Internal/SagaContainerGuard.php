<?php

declare(strict_types=1);

namespace Pulsar\Saga\Internal;

use Pulsar\Api\Internal;
use Pulsar\Saga\Exception\ForbiddenInjectionException;
use Pulsar\Saga\Port\IntegrationEventBusPort;

use function str_contains;

/**
 * Runtime container guard that prevents saga step handlers from resolving
 * forbidden services.
 *
 * In regulated presets, this guard is enabled by default. It intercepts
 * container resolutions during saga step execution and throws
 * ForbiddenInjectionException if a step handler attempts to resolve
 * IntegrationEventBusPort directly.
 *
 * Usage: Wrap the container's get() call during saga step execution:
 *
 *     $guard = new SagaContainerGuard(enabled: true);
 *     $guard->assertAllowed($serviceId, $requestingClass);
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Used by saga orchestrator internals')]
final readonly class SagaContainerGuard
{
    public function __construct(
        private bool $enabled = true,
    ) {}

    /**
     * Assert that the requested service is allowed for the given handler.
     *
     * @param string $serviceId       The service being resolved from the container
     * @param string $requestingClass The class requesting the service
     *
     * @throws ForbiddenInjectionException If the service is forbidden for the handler
     */
    public function assertAllowed(string $serviceId, string $requestingClass): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->isSagaStepHandler($requestingClass)) {
            return;
        }

        if ($serviceId === IntegrationEventBusPort::class) {
            throw ForbiddenInjectionException::integrationEventBusInSagaStep($requestingClass);
        }
    }

    private function isSagaStepHandler(string $className): bool
    {
        return str_contains($className, 'Saga\\Step\\')
            || str_contains($className, 'Saga\\Handler\\')
            || str_contains($className, 'Saga\\Action\\');
    }
}
