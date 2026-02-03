<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;

#[CoversClass(InMemoryRoleRegistry::class)]
final class InMemoryRoleRegistryTest extends TestCase
{
    #[Test]
    public function findByNameReturnsNullForUnregisteredRole(): void
    {
        $registry = new InMemoryRoleRegistry();

        self::assertNull($registry->findByName('nonexistent'));
    }

    #[Test]
    public function findByNameReturnsRoleAfterRegistration(): void
    {
        $registry = new InMemoryRoleRegistry();
        $role = new Role(
            name: 'editor',
            permissions: [new Permission('posts.create')],
        );

        $registry->register($role);

        $found = $registry->findByName('editor');
        self::assertNotNull($found);
        self::assertSame('editor', $found->name);
        self::assertSame($role, $found);
    }

    #[Test]
    public function registerStoresRole(): void
    {
        $registry = new InMemoryRoleRegistry();
        $role = new Role(
            name: 'viewer',
            permissions: [new Permission('reports.view')],
        );

        self::assertNull($registry->findByName('viewer'));

        $registry->register($role);

        self::assertNotNull($registry->findByName('viewer'));
    }

    #[Test]
    public function permissionsForRolesReturnsMergedPermissionsFromMultipleRoles(): void
    {
        $registry = new InMemoryRoleRegistry();

        $editor = new Role(
            name: 'editor',
            permissions: [
                new Permission('posts.create'),
                new Permission('posts.update'),
            ],
        );

        $moderator = new Role(
            name: 'moderator',
            permissions: [
                new Permission('posts.delete'),
                new Permission('comments.moderate'),
            ],
        );

        $registry->register($editor);
        $registry->register($moderator);

        $permissions = $registry->permissionsForRoles(['editor', 'moderator']);

        self::assertCount(4, $permissions);

        $names = array_map(
            static fn(Permission $p): string => $p->name,
            $permissions,
        );

        self::assertContains('posts.create', $names);
        self::assertContains('posts.update', $names);
        self::assertContains('posts.delete', $names);
        self::assertContains('comments.moderate', $names);
    }

    #[Test]
    public function permissionsForRolesIgnoresUnknownRoleNames(): void
    {
        $registry = new InMemoryRoleRegistry();

        $editor = new Role(
            name: 'editor',
            permissions: [new Permission('posts.create')],
        );

        $registry->register($editor);

        $permissions = $registry->permissionsForRoles(['editor', 'nonexistent', 'ghost']);

        self::assertCount(1, $permissions);
        self::assertSame('posts.create', $permissions[0]->name);
    }
}
