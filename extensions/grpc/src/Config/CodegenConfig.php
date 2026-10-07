<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for the protoc codegen wrapper.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CodegenConfig
{
    public function __construct(
        public string $protoPath = 'proto',
        public string $outputPath = 'src/Generated',
        public string $protocBinary = 'protoc',
        public string $grpcPhpPlugin = 'grpc_php_plugin',
    ) {}

    /**
     * @param array{
     *     proto_path?: string,
     *     output_path?: string,
     *     protoc_binary?: string,
     *     grpc_php_plugin?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            protoPath: $data['proto_path'] ?? 'proto',
            outputPath: $data['output_path'] ?? 'src/Generated',
            protocBinary: $data['protoc_binary'] ?? 'protoc',
            grpcPhpPlugin: $data['grpc_php_plugin'] ?? 'grpc_php_plugin',
        );
    }
}
