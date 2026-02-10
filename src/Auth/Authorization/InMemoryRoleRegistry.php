<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use Override;

use function array_key_exists;
use function array_merge;

/**
 * In-memory role registry, typically populated from configuration.
 */
final class InMemoryRoleRegistry implements RoleRegistryInterface
{
    /** @var array<string, Role> */
    private array $roles = [];

    #[Override]
    public function findByName(string $name): ?Role
    {
        return $this->roles[$name] ?? null;
    }

    #[Override]
    public function permissionsForRoles(array $roleNames): array
    {
        $permissions = [];

        foreach ($roleNames as $roleName) {
            if (array_key_exists($roleName, $this->roles)) {
                $permissions = array_merge($permissions, $this->roles[$roleName]->permissions);
            }
        }

        return $permissions;
    }

    #[Override]
    public function register(Role $role): void
    {
        $this->roles[$role->name] = $role;
    }
}
