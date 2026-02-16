<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Server;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;

use function array_keys;

/**
 * In-memory service registry populated from compiled service manifests.
 *
 * Stores services keyed by name and builds a method lookup map of the form
 * "/package.Service/Method" => (handler, descriptor) for O(1) method resolution.
 * No runtime reflection: all data comes from pre-compiled manifests.
 */
#[Internal(reason: 'Registry implementation; use ServiceRegistryInterface')]
final class ServiceRegistry implements ServiceRegistryInterface
{
    /** @var array<string, ServiceHandlerInterface> Keyed by service name */
    private array $services = [];

    /** @var array<string, MethodDescriptor> Keyed by full method name ("/package.Service/Method") */
    private array $methodMap = [];

    /** @var array<string, ServiceHandlerInterface> Keyed by full method name */
    private array $handlerMap = [];

    #[Override]
    public function register(ServiceHandlerInterface $handler): void
    {
        $serviceName = $handler->serviceName();
        $this->services[$serviceName] = $handler;

        foreach ($handler->methods() as $methodName => $descriptor) {
            $fullName = '/' . $serviceName . '/' . $methodName;
            $this->methodMap[$fullName] = $descriptor;
            $this->handlerMap[$fullName] = $handler;
        }
    }

    #[Override]
    public function resolveMethod(string $fullMethodName): ?MethodDescriptor
    {
        return $this->methodMap[$fullMethodName] ?? null;
    }

    #[Override]
    public function resolveHandler(string $fullMethodName): ?ServiceHandlerInterface
    {
        return $this->handlerMap[$fullMethodName] ?? null;
    }

    #[Override]
    public function resolve(string $fullMethodName): ?array
    {
        $method = $this->methodMap[$fullMethodName] ?? null;
        $handler = $this->handlerMap[$fullMethodName] ?? null;

        if ($method === null || $handler === null) {
            return null;
        }

        return [$method, $handler];
    }

    #[Override]
    public function serviceNames(): array
    {
        return array_keys($this->services);
    }

    #[Override]
    public function methodsForService(string $serviceName): array
    {
        $handler = $this->services[$serviceName] ?? null;

        if ($handler === null) {
            return [];
        }

        return $handler->methods();
    }

    #[Override]
    public function isEmpty(): bool
    {
        return $this->services === [];
    }
}
