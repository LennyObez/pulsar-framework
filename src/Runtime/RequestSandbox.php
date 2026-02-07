<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

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
    ) {}

    /**
     * Enter request scope: snapshot memory baseline for leak detection.
     */
    public function beforeRequest(Request $request): Request
    {
        $this->leakDetector->beginRequest();

        return $request;
    }

    /**
     * Exit request scope: evict, reset, and check for leaks.
     *
     * Execution order (deterministic):
     * 1. Evict request-bound services (forgetInstance)
     * 2. Reset resettable singletons (resetRequestState)
     * 3. Check leak detector for warnings
     *
     * @return list<string> Leak warnings (empty if clean)
     */
    public function afterRequest(Request $request, Response $response): array
    {
        // 1. Evict request-bound services first
        foreach ($this->registry->getEvictableIds() as $id) {
            $this->container->forgetInstance($id);
        }

        // 2. Reset resettable singletons in deterministic order
        foreach ($this->registry->getResettableIds() as $id) {
            if ($this->container->has($id)) {
                $service = $this->container->get($id);

                if ($service instanceof ResettableInterface) {
                    $service->resetRequestState();
                }
            }
        }

        // 3. Check leak detector
        return $this->leakDetector->endRequest();
    }
}
