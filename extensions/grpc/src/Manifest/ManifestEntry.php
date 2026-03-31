<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Manifest;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * A single service entry in the compiled service manifest.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ManifestEntry
{
    /**
     * @param string $serviceName                                     Fully qualified protobuf service name
     * @param string $handlerClass                                    PHP class implementing ServiceHandlerInterface
     * @param list<array{name: string, full_name: string, type: string, input_type: string, output_type: string, handler: string}> $methods  Method descriptors
     */
    public function __construct(
        public string $serviceName,
        public string $handlerClass,
        public array $methods,
    ) {}

    /**
     * @param array{
     *     service_name?: string,
     *     handler_class?: string,
     *     methods?: list<array{name: string, full_name: string, type: string, input_type: string, output_type: string, handler: string}>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            serviceName: $data['service_name'] ?? '',
            handlerClass: $data['handler_class'] ?? '',
            methods: $data['methods'] ?? [],
        );
    }

    /**
     * @return array{service_name: string, handler_class: string, methods: list<array{name: string, full_name: string, type: string, input_type: string, output_type: string, handler: string}>}
     */
    public function toArray(): array
    {
        return [
            'service_name' => $this->serviceName,
            'handler_class' => $this->handlerClass,
            'methods' => $this->methods,
        ];
    }
}
