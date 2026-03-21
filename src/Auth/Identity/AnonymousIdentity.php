<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Override;
use Pulsar\Api\Api;

/**
 * Null object for unauthenticated requests.
 *
 * Always returns false for isAuthenticated() and empty values for all fields.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AnonymousIdentity implements IdentityInterface
{
    #[Override]
    public function id(): string
    {
        return '';
    }

    #[Override]
    public function displayName(): string
    {
        return 'Anonymous';
    }

    #[Override]
    public function roles(): array
    {
        return [];
    }

    #[Override]
    public function hasRole(string $role): false
    {
        return false;
    }

    #[Override]
    public function twoFactorStatus(): TwoFactorStatus
    {
        return TwoFactorStatus::Disabled;
    }

    #[Override]
    public function isAuthenticated(): false
    {
        return false;
    }

    #[Override]
    public function attributes(): array
    {
        return [];
    }

    #[Override]
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
