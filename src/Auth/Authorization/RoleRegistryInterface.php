<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Pulsar\Api\Api;

/**
 * Contract for role storage and lookup.
 */
#[Api(since: '1.0.0')]
interface RoleRegistryInterface
{
    /**
     * Find a role by name.
     */
    public function findByName(string $name): ?Role;

    /**
     * Get all permissions granted by the given role names.
     *
     * @param list<string> $roleNames
     * @return list<Permission>
     */
    public function permissionsForRoles(array $roleNames): array;

    /**
     * Register a role definition.
     */
    public function register(Role $role): void;
}
