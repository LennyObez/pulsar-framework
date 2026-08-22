<?php

declare(strict_types=1);

namespace Pulsar\Auth\Database;

use Pulsar\Api\Api;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Seeder\SeederInterface;

use function array_map;

/**
 * Seeds default user roles with their associated permissions.
 *
 * Registers five standard roles: admin (full access), moderator (content + user management),
 * trusted (extended content permissions), member (basic access), and guest (read-only).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RoleSeeder implements SeederInterface
{
    public function __construct(
        private RoleRegistryInterface $roleRegistry,
    ) {}

    public function identifier(): string
    {
        return 'auth:roles';
    }

    public function run(ConnectionInterface $connection): void
    {
        foreach (self::definitions() as $definition) {
            $permissions = array_map(
                static fn(string $name): Permission => new Permission($name),
                $definition['permissions'],
            );

            $role = new Role(
                name: $definition['slug'],
                permissions: $permissions,
            );

            $this->roleRegistry->register($role);
        }
    }

    /**
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     permissions: list<string>
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full system access with all permissions granted.',
                'permissions' => ['*'],
            ],
            [
                'slug' => 'moderator',
                'name' => 'Moderator',
                'description' => 'Content moderation and user management capabilities.',
                'permissions' => [
                    'content.create',
                    'content.read',
                    'content.update',
                    'content.delete',
                    'content.publish',
                    'content.moderate',
                    'comments.read',
                    'comments.moderate',
                    'comments.delete',
                    'users.read',
                    'users.ban',
                    'media.read',
                    'media.upload',
                    'media.delete',
                ],
            ],
            [
                'slug' => 'trusted',
                'name' => 'Trusted Member',
                'description' => 'Extended content permissions for established community members.',
                'permissions' => [
                    'content.create',
                    'content.read',
                    'content.update',
                    'comments.create',
                    'comments.read',
                    'comments.update',
                    'media.read',
                    'media.upload',
                    'profile.read',
                    'profile.update',
                ],
            ],
            [
                'slug' => 'member',
                'name' => 'Member',
                'description' => 'Basic authenticated user with standard access.',
                'permissions' => [
                    'content.read',
                    'comments.create',
                    'comments.read',
                    'media.read',
                    'profile.read',
                    'profile.update',
                ],
            ],
            [
                'slug' => 'guest',
                'name' => 'Guest',
                'description' => 'Unauthenticated visitor with read-only access.',
                'permissions' => [
                    'content.read',
                    'comments.read',
                    'media.read',
                ],
            ],
        ];
    }
}
