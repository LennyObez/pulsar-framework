<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Forum\Config\ForumPermissions;

final class ForumPermissionsTest extends TestCase
{
    #[Test]
    public function registerCallsRegistryFourTimes(): void
    {
        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(4))
            ->method('register')
            ->with(self::isInstanceOf(Role::class));

        ForumPermissions::register($registry);
    }

    #[Test]
    public function registerCreatesViewerMemberModeratorAdminRoles(): void
    {
        $roles = [];
        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(4))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$roles): void {
                $roles[] = $role->name;
            });

        ForumPermissions::register($registry);

        self::assertSame([
            'forum.viewer',
            'forum.member',
            'forum.moderator',
            'forum.admin',
        ], $roles);
    }

    #[Test]
    public function viewerRoleHasOnlyViewPermissions(): void
    {
        $captured = null;
        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(4))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$captured): void {
                if ($role->name === 'forum.viewer') {
                    $captured = $role;
                }
            });

        ForumPermissions::register($registry);

        self::assertNotNull($captured);
        $permNames = array_map(
            static fn($p) => $p->name,
            $captured->permissions,
        );

        self::assertContains('forum.threads.view', $permNames);
        self::assertContains('forum.posts.view', $permNames);
        self::assertNotContains('forum.threads.create', $permNames);
    }

    #[Test]
    public function adminRoleInheritesAllPermissions(): void
    {
        $captured = null;
        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(4))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$captured): void {
                if ($role->name === 'forum.admin') {
                    $captured = $role;
                }
            });

        ForumPermissions::register($registry);

        self::assertNotNull($captured);
        $permNames = array_map(
            static fn($p) => $p->name,
            $captured->permissions,
        );

        // Admin should have viewer, member, moderator, and admin permissions
        self::assertContains('forum.threads.view', $permNames);
        self::assertContains('forum.threads.create', $permNames);
        self::assertContains('forum.threads.lock', $permNames);
        self::assertContains('forum.categories.create', $permNames);
        self::assertContains('forum.settings.manage', $permNames);
    }
}
