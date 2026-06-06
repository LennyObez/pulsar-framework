<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Container\AdvancedContainerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Runtime\Hygiene\HygieneProfileInterface;

/**
 * Orchestrates per-request state isolation in the persistent runtime.
 *
 * Executes a deterministic sequence: evict request-bound services,
 * reset resettable singletons, and run leak detection.
 */
#[Internal]
final readonly class RequestSandbox
{
    public function __construct(
        private ContainerInterface $container,
        private RequestResetRegistry $registry,
        private LeakDetector $leakDetector,
        private ?HygieneProfileInterface $hygiene = null,
    ) {}

    /**
     * Enter request scope: snapshot memory baseline for leak detection.
     */
    public function beforeRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // Apply hygiene FIRST (before leak detection baseline)
        $this->hygiene?->apply();

        $this->leakDetector->beginRequest();

        if ($this->container instanceof AdvancedContainerInterface) {
            $this->container->beginRequestScope();
        }

        return $request;
    }

    /**
     * Exit request scope: evict, reset, and check for leaks.
     *
     * Execution order (deterministic):
     * 1. End request scope (evicts scoped instances via ScopeManager)
     * 2. Evict legacy request-bound services (forgetInstance)
     * 3. Reset resettable singletons (resetRequestState)
     * 4. Check leak detector for warnings
     *
     * @return list<string> Leak warnings (empty if clean)
     *
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function afterRequest(ServerRequestInterface $request, ResponseInterface $response): array
    {
        // 1. End request scope (if container supports it)
        if ($this->container instanceof AdvancedContainerInterface) {
            $this->container->endRequestScope();
        }

        // 2. Evict legacy request-bound services
        foreach ($this->registry->evictableIds as $id) {
            $this->container->forgetInstance($id);
        }

        // 3. Reset resettable singletons in deterministic order
        foreach ($this->registry->resettableIds as $id) {
            if ($this->container->has($id)) {
                /** @var mixed $service */
                $service = $this->container->get($id);

                if ($service instanceof ResettableInterface) {
                    $service->resetRequestState();
                }
            }
        }

        // 4. Check leak detector
        return $this->leakDetector->endRequest();
    }
}
