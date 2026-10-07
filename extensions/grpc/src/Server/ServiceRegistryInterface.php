<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Server;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;

/**
 * Registry of gRPC services and their method descriptors.
 *
 * Populated from the compiled service manifest at boot time: no runtime
 * reflection or scanning. Services are resolved by fully qualified method name.
 * @api
 */
#[Api(since: '1.0.0')]
interface ServiceRegistryInterface
{
    /**
     * Register a service handler.
     */
    public function register(ServiceHandlerInterface $handler): void;

    /**
     * Resolve a method descriptor by fully qualified method name.
     *
     * @param string $fullMethodName e.g. "/package.Service/Method"
     */
    public function resolveMethod(string $fullMethodName): ?MethodDescriptor;

    /**
     * Resolve the service handler for a fully qualified method name.
     *
     * @param string $fullMethodName e.g. "/package.Service/Method"
     */
    public function resolveHandler(string $fullMethodName): ?ServiceHandlerInterface;

    /**
     * Resolve both method descriptor and handler in a single lookup.
     *
     * @param string $fullMethodName e.g. "/package.Service/Method"
     *
     * @return array{MethodDescriptor, ServiceHandlerInterface}|null
     */
    public function resolve(string $fullMethodName): ?array;

    /**
     * Get all registered service names.
     *
     * @return list<string>
     */
    public function serviceNames(): array;

    /**
     * Get all method descriptors for a service.
     *
     * @return array<string, MethodDescriptor> Keyed by method name
     */
    public function methodsForService(string $serviceName): array;

    /**
     * Whether any services are registered.
     */
    public function isEmpty(): bool;
}
