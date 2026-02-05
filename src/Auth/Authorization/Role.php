<?php

declare(strict_types=1);

namespace Pulsar\Auth\Authorization;

use NoDiscard;
use Pulsar\Api\Api;

use function array_any;
use function array_map;

/**
 * Immutable value object representing a role with its associated permissions.
 */
#[Api(since: '1.0.0')]
readonly class Role
{
    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        public string $name,
        public array $permissions = [],
    ) {}

    /**
     * Check if this role grants the given permission.
     */
    public function hasPermission(string $permission): bool
    {
        return array_any(
            $this->permissions,
            static fn(Permission $p): bool => $p->matches($permission),
        );
    }

    /**
     * Build a Role from a raw array.
     *
     * @param array<string, mixed> $data Expected keys: 'permissions' => list<string>
     */
    #[NoDiscard]
    public static function fromArray(string $name, array $data): self
    {
        /** @var list<string> $permissionNames */
        $permissionNames = $data['permissions'] ?? [];

        $permissions = array_map(
            static fn(string $name): Permission => new Permission($name),
            $permissionNames,
        );

        return new self(
            name: $name,
            permissions: $permissions,
        );
    }
}
