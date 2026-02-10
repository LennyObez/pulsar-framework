<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use Pulsar\Api\Internal;

/**
 * Holds discovered method metadata from protoc output.
 */
#[Internal(reason: 'Codegen internal DTO')]
final readonly class MethodInfo
{
    /**
     * @param string $name       Method name (e.g. "SayHello")
     * @param string $inputType  Fully qualified protobuf input type
     * @param string $outputType Fully qualified protobuf output type
     */
    public function __construct(
        public string $name,
        public string $inputType,
        public string $outputType,
    ) {}
}
