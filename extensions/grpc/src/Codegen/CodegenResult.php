<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Codegen;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result DTO from protoc code generation.
 */
#[Api(since: '1.0.0')]
final readonly class CodegenResult
{
    /**
     * @param bool $success         Whether code generation succeeded
     * @param list<string> $generatedFiles  Paths of generated files
     * @param list<string> $errors          Error messages from protoc or validation
     * @param string $protocVersion The protoc version used
     */
    public function __construct(
        public bool $success,
        public array $generatedFiles = [],
        public array $errors = [],
        public string $protocVersion = '',
    ) {}

    /**
     * Create a successful result.
     *
     * @param list<string> $generatedFiles
     */
    #[NoDiscard]
    public static function success(array $generatedFiles, string $protocVersion): self
    {
        return new self(
            success: true,
            generatedFiles: $generatedFiles,
            protocVersion: $protocVersion,
        );
    }

    /**
     * Create a failure result.
     *
     * @param list<string> $errors
     */
    #[NoDiscard]
    public static function failure(array $errors, string $protocVersion = ''): self
    {
        return new self(
            success: false,
            errors: $errors,
            protocVersion: $protocVersion,
        );
    }
}
