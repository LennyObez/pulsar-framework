<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Domain exception for gRPC transport and lifecycle failures.
 *
 * Static factories expose the exact failure mode so callers can pattern-match
 * on semantic intent (missing extension, filesystem write failure, service
 * provider misconfiguration) without string-parsing exception messages.
 * @api
 */
#[Api(since: '1.0.0')]
final class GrpcException extends RuntimeException
{
    public static function extensionNotLoaded(string $extensionName): self
    {
        return new self(sprintf(
            'The %s PHP extension is not loaded. Install it via: pecl install %s',
            $extensionName,
            $extensionName,
        ));
    }

    public static function cannotCreateDirectory(string $path): self
    {
        return new self(sprintf('Cannot create directory: %s', $path));
    }

    public static function cannotCreateOutputDirectory(string $path): self
    {
        return new self(sprintf('Cannot create output directory: %s', $path));
    }

    public static function failedToWriteManifest(string $path): self
    {
        return new self(sprintf('Failed to write manifest to: %s', $path));
    }

    public static function invalidConfiguration(string $reason): self
    {
        return new self('gRPC configuration is invalid: ' . $reason);
    }

    public static function serverStartFailed(string $reason): self
    {
        return new self('gRPC server failed to start: ' . $reason);
    }
}
