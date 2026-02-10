<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Handler;

use Pulsar\Api\Api;

/**
 * Contract for gRPC service handlers.
 *
 * Each service handler represents a single protobuf service and exposes
 * its methods as MethodDescriptor instances. The handler is responsible
 * for dispatching calls to the appropriate implementation method.
 */
#[Api(since: '1.0.0')]
interface ServiceHandlerInterface
{
    /**
     * The fully qualified protobuf service name (e.g. "helloworld.Greeter").
     */
    public function serviceName(): string;

    /**
     * Get all method descriptors for this service.
     *
     * @return array<string, MethodDescriptor> Keyed by method name
     */
    public function methods(): array;

    /**
     * Invoke a method by name with the serialized request payload.
     *
     * @param string $method  Method name (e.g. "SayHello")
     * @param string $payload Serialized protobuf request
     *
     * @return string Serialized protobuf response
     */
    public function invoke(string $method, string $payload): string;
}
