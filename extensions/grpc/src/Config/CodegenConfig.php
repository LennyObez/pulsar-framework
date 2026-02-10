<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Configuration for the protoc codegen wrapper.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            protoPath: is_string($data['proto_path'] ?? null) ? $data['proto_path'] : 'proto',
            outputPath: is_string($data['output_path'] ?? null) ? $data['output_path'] : 'src/Generated',
            protocBinary: is_string($data['protoc_binary'] ?? null) ? $data['protoc_binary'] : 'protoc',
            grpcPhpPlugin: is_string($data['grpc_php_plugin'] ?? null)
                ? $data['grpc_php_plugin'] : 'grpc_php_plugin',
        );
    }
}
