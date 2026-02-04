<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;

#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    #[Test]
    public function hasPermissionReturnsTrueForMatchingPermission(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: [
                new Permission('posts.create'),
                new Permission('posts.update'),
            ],
        );

        self::assertTrue($role->hasPermission('posts.create'));
        self::assertTrue($role->hasPermission('posts.update'));
    }

    #[Test]
    public function hasPermissionReturnsFalseForNonMatchingPermission(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: [
                new Permission('posts.create'),
            ],
        );

        self::assertFalse($role->hasPermission('users.delete'));
        self::assertFalse($role->hasPermission('posts.delete'));
    }

    #[Test]
    public function fromArrayCorrectlyBuildsRoleWithPermissions(): void
    {
        $role = Role::fromArray('manager', [
            'permissions' => ['users.create', 'users.update', 'reports.view'],
        ]);

        self::assertSame('manager', $role->name);
        self::assertCount(3, $role->permissions);
        self::assertTrue($role->hasPermission('users.create'));
        self::assertTrue($role->hasPermission('users.update'));
        self::assertTrue($role->hasPermission('reports.view'));
        self::assertFalse($role->hasPermission('users.delete'));
    }

    #[Test]
    public function wildcardPermissionsOnRoleWorkCorrectly(): void
    {
        $role = new Role(
            name: 'admin',
            permissions: [
                new Permission('users.*'),
            ],
        );

        self::assertTrue($role->hasPermission('users.create'));
        self::assertTrue($role->hasPermission('users.delete'));
        self::assertTrue($role->hasPermission('users.update'));
        self::assertFalse($role->hasPermission('posts.create'));
    }
}
