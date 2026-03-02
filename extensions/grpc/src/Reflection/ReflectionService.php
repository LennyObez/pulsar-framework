<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Reflection;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;

/**
 * Simplified gRPC server reflection service.
 *
 * Lists registered services and their method descriptors for tooling
 * (grpcurl, grpcui, etc.). Does NOT include proto file descriptors --
 * only service/method metadata from the compiled service registry.
 */
#[Api(since: '1.0.0')]
final readonly class ReflectionService
{
    public function __construct(
        private ServiceRegistryInterface $registry,
    ) {}

    /**
     * List all registered gRPC service names.
     *
     * @return list<string>
     */
    public function listServices(): array
    {
        return $this->registry->serviceNames();
    }

    /**
     * Get method descriptors for a specific service.
     *
     * @return array<string, MethodDescriptor> Keyed by method name, empty if service not found
     */
    public function methodsForService(string $serviceName): array
    {
        return $this->registry->methodsForService($serviceName);
    }
}
