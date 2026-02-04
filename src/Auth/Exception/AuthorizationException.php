<?php

declare(strict_types=1);

namespace Pulsar\Auth\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for authorization failures.
 */
#[Api(since: '1.0.0')]
final class AuthorizationException extends RuntimeException
{
    #[NoDiscard]
    public static function permissionDenied(string $permission): self
    {
        return new self(sprintf('Permission denied: "%s"', $permission));
    }

    #[NoDiscard]
    public static function roleNotFound(string $role): self
    {
        return new self(sprintf('Role not found: "%s"', $role));
    }

    #[NoDiscard]
    public static function policyDenied(string $policy): self
    {
        return new self(sprintf('Policy denied access: "%s"', $policy));
    }

    #[NoDiscard]
    public static function unauthenticated(): self
    {
        return new self('Authentication is required');
    }

    #[NoDiscard]
    public static function twoFactorRequired(): self
    {
        return new self('Two-factor authentication verification is required');
    }
}
