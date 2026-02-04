<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Immutable value object representing a permission.
 *
 * Supports wildcard matching: "users.*" matches "users.create", "users.delete", etc.
 */
readonly class Permission
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * Check if this permission matches the given permission name.
     *
     * Supports wildcard patterns:
     * - "users.*" matches "users.create"
     * - "*" matches everything
     */
    public function matches(string $permission): bool
    {
        if ($this->name === $permission) {
            return true;
        }

        if ($this->name === '*') {
            return true;
        }

        if (str_ends_with($this->name, '.*')) {
            $prefix = substr($this->name, 0, -1);
            return str_starts_with($permission, $prefix);
        }

        return false;
    }
}
