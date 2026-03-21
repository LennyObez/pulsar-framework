<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Exception for cloud provider operation failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class CloudException extends RuntimeException
{
    #[NoDiscard]
    public static function providerNotConfigured(string $provider): self
    {
        return new self(sprintf('Cloud provider "%s" is not configured', $provider));
    }

    #[NoDiscard]
    public static function authenticationFailed(string $provider, string $reason): self
    {
        return new self(sprintf('Cloud provider "%s" authentication failed: %s', $provider, $reason));
    }

    #[NoDiscard]
    public static function requestFailed(string $service, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Cloud service "%s" request failed: %s', $service, $reason),
            previous: $previous,
        );
    }

    #[NoDiscard]
    public static function secretNotFound(string $provider, string $secretId): self
    {
        return new self(sprintf('Secret "%s" not found in %s', $secretId, $provider));
    }

    #[NoDiscard]
    public static function connectionFailed(string $service, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Connection to cloud service "%s" failed: %s', $service, $reason),
            previous: $previous,
        );
    }
}
