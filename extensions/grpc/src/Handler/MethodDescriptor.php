<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Handler;

use Pulsar\Api\Api;

/**
 * Describes a single RPC method within a gRPC service.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MethodDescriptor
{
    /**
     * @param string $name          Method name (e.g. "SayHello")
     * @param string $fullName      Fully qualified name (e.g. "/helloworld.Greeter/SayHello")
     * @param MethodType $type      Unary, server streaming, client streaming, or bidirectional
     * @param string $inputType     Fully qualified protobuf message type for the request
     * @param string $outputType    Fully qualified protobuf message type for the response
     * @param string $handler       Callable reference (class::method) for the implementation
     */
    public function __construct(
        public string $name,
        public string $fullName,
        public MethodType $type,
        public string $inputType,
        public string $outputType,
        public string $handler,
    ) {}

    /**
     * @param array{
     *     name?: string,
     *     full_name?: string,
     *     type?: string,
     *     input_type?: string,
     *     output_type?: string,
     *     handler?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            fullName: $data['full_name'] ?? '',
            type: MethodType::from($data['type'] ?? 'unary'),
            inputType: $data['input_type'] ?? '',
            outputType: $data['output_type'] ?? '',
            handler: $data['handler'] ?? '',
        );
    }

    /**
     * @return array{name: string, full_name: string, type: string, input_type: string, output_type: string, handler: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'full_name' => $this->fullName,
            'type' => $this->type->value,
            'input_type' => $this->inputType,
            'output_type' => $this->outputType,
            'handler' => $this->handler,
        ];
    }
}
