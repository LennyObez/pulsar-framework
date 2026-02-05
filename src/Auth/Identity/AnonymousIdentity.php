<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Pulsar\Api\Api;

/**
 * Null object for unauthenticated requests.
 *
 * Always returns false for isAuthenticated() and empty values for all fields.
 */
#[Api]
readonly class AnonymousIdentity implements IdentityInterface
{
    public function id(): string
    {
        return '';
    }

    public function displayName(): string
    {
        return 'Anonymous';
    }

    public function roles(): array
    {
        return [];
    }

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function twoFactorStatus(): TwoFactorStatus
    {
        return TwoFactorStatus::Disabled;
    }

    public function isAuthenticated(): bool
    {
        return false;
    }

    public function attributes(): array
    {
        return [];
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
