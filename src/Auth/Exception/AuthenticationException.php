<?php

declare(strict_types=1);

namespace Pulsar\Auth\Exception;

use RuntimeException;

use function sprintf;

/**
 * Exception for authentication failures.
 */
final class AuthenticationException extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('Invalid credentials');
    }

    public static function unknownGuard(string $name): self
    {
        return new self(sprintf('Unknown authentication guard: "%s"', $name));
    }

    public static function noGuardsConfigured(): self
    {
        return new self('No authentication guards are configured');
    }

    public static function sessionExpired(): self
    {
        return new self('Session has expired');
    }

    public static function tokenMissing(): self
    {
        return new self('Authentication token is missing');
    }

    public static function tokenInvalid(): self
    {
        return new self('Authentication token is invalid');
    }
}
