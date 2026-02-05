<?php

declare(strict_types=1);

namespace Pulsar\Auth\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for authorization failures.
 */
#[Api]
final class AuthorizationException extends RuntimeException
{
    public static function permissionDenied(string $permission): self
    {
        return new self(sprintf('Permission denied: "%s"', $permission));
    }

    public static function roleNotFound(string $role): self
    {
        return new self(sprintf('Role not found: "%s"', $role));
    }

    public static function policyDenied(string $policy): self
    {
        return new self(sprintf('Policy denied access: "%s"', $policy));
    }

    public static function unauthenticated(): self
    {
        return new self('Authentication is required');
    }

    public static function twoFactorRequired(): self
    {
        return new self('Two-factor authentication verification is required');
    }
}
