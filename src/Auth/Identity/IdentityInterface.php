<?php

declare(strict_types=1);

namespace Pulsar\Auth\Identity;

use Pulsar\Api\Api;

/**
 * Represents an authenticated (or anonymous) identity in the system.
 */
#[Api]
interface IdentityInterface
{
    /**
     * Get the unique identifier for this identity.
     */
    public function id(): string;

    /**
     * Get the display name for this identity.
     */
    public function displayName(): string;

    /**
     * Get the roles assigned to this identity.
     *
     * @return list<string>
     */
    public function roles(): array;

    /**
     * Check if this identity has a specific role.
     */
    public function hasRole(string $role): bool;

    /**
     * Get the two-factor authentication status.
     */
    public function twoFactorStatus(): TwoFactorStatus;

    /**
     * Check if this identity represents an authenticated user.
     */
    public function isAuthenticated(): bool;

    /**
     * Get all custom attributes.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array;

    /**
     * Get a single custom attribute.
     */
    public function attribute(string $key, mixed $default = null): mixed;
}
