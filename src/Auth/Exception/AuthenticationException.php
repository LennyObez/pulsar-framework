<?php

declare(strict_types=1);

namespace Pulsar\Auth\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for authentication failures.
 */
#[Api(since: '1.0.0')]
final class AuthenticationException extends RuntimeException
{
    #[NoDiscard]
    public static function invalidCredentials(): self
    {
        return new self('Invalid credentials');
    }

    #[NoDiscard]
    public static function unknownGuard(string $name): self
    {
        return new self(sprintf('Unknown authentication guard: "%s"', $name));
    }

    #[NoDiscard]
    public static function noGuardsConfigured(): self
    {
        return new self('No authentication guards are configured');
    }

    #[NoDiscard]
    public static function sessionExpired(): self
    {
        return new self('Session has expired');
    }

    #[NoDiscard]
    public static function tokenMissing(): self
    {
        return new self('Authentication token is missing');
    }

    #[NoDiscard]
    public static function tokenInvalid(): self
    {
        return new self('Authentication token is invalid');
    }

    #[NoDiscard]
    public static function invalidIdentityData(string $reason): self
    {
        return new self(sprintf('Invalid identity data: %s', $reason));
    }
}
