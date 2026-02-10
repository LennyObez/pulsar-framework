<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use Pulsar\Api\Internal;

/**
 * Holds discovered service metadata from protoc output.
 */
#[Internal(reason: 'Codegen internal DTO')]
final readonly class ServiceInfo
{
    /**
     * @param string $name             Service name (e.g. "Greeter")
     * @param string $protoNamespace   Protobuf package namespace
     * @param list<MethodInfo> $methods  Methods in this service
     */
    public function __construct(
        public string $name,
        public string $protoNamespace,
        public array $methods,
    ) {}
}
