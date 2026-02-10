<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Forum\Config\ForumPermissions;

#[CoversClass(ForumPermissions::class)]
final class ForumPermissionsTest extends TestCase
{
    private RoleRegistryInterface&Stub $registry;

    /** @var list<Role> */
    private array $registeredRoles = [];

    protected function setUp(): void
    {
        $this->registry = $this->createStub(RoleRegistryInterface::class);
        $this->registeredRoles = [];
        $this->registry->method('register')->willReturnCallback(function (Role $role): void {
            $this->registeredRoles[] = $role;
        });
    }

    #[Test]
    public function registerCreatesFourRoles(): void
    {
        ForumPermissions::register($this->registry);
        self::assertCount(4, $this->registeredRoles);
    }

    #[Test]
    public function registerCreatesViewerRole(): void
    {
        ForumPermissions::register($this->registry);
        $viewer = $this->findRole('forum.viewer');
        self::assertNotNull($viewer);
        $permNames = $this->permissionNames($viewer);
        self::assertContains('forum.threads.view', $permNames);
        self::assertContains('forum.posts.view', $permNames);
        self::assertNotContains('forum.threads.create', $permNames);
    }

    #[Test]
    public function registerCreatesMemberRole(): void
    {
        ForumPermissions::register($this->registry);
        $member = $this->findRole('forum.member');
        self::assertNotNull($member);
        $permNames = $this->permissionNames($member);
        self::assertContains('forum.threads.view', $permNames);
        self::assertContains('forum.threads.create', $permNames);
        self::assertContains('forum.votes.cast', $permNames);
        self::assertNotContains('forum.threads.delete', $permNames);
    }

    #[Test]
    public function registerCreatesModeratorRole(): void
    {
        ForumPermissions::register($this->registry);
        $moderator = $this->findRole('forum.moderator');
        self::assertNotNull($moderator);
        $permNames = $this->permissionNames($moderator);
        self::assertContains('forum.threads.delete', $permNames);
        self::assertContains('forum.threads.lock', $permNames);
        self::assertContains('forum.users.ban', $permNames);
        self::assertNotContains('forum.categories.create', $permNames);
    }

    #[Test]
    public function registerCreatesAdminRole(): void
    {
        ForumPermissions::register($this->registry);
        $admin = $this->findRole('forum.admin');
        self::assertNotNull($admin);
        $permNames = $this->permissionNames($admin);
        self::assertContains('forum.categories.create', $permNames);
        self::assertContains('forum.badges.manage', $permNames);
        self::assertContains('forum.settings.manage', $permNames);
    }

    #[Test]
    public function roleHierarchyIsAdditive(): void
    {
        ForumPermissions::register($this->registry);
        $viewer = $this->findRole('forum.viewer');
        $member = $this->findRole('forum.member');
        $moderator = $this->findRole('forum.moderator');
        $admin = $this->findRole('forum.admin');
        self::assertNotNull($viewer);
        self::assertNotNull($member);
        self::assertNotNull($moderator);
        self::assertNotNull($admin);

        $viewerPerms = $this->permissionNames($viewer);
        $memberPerms = $this->permissionNames($member);
        $moderatorPerms = $this->permissionNames($moderator);
        $adminPerms = $this->permissionNames($admin);

        foreach ($viewerPerms as $perm) {
            self::assertContains($perm, $memberPerms, "Member should have viewer perm: {$perm}");
        }
        foreach ($memberPerms as $perm) {
            self::assertContains($perm, $moderatorPerms, "Moderator should have member perm: {$perm}");
        }
        foreach ($moderatorPerms as $perm) {
            self::assertContains($perm, $adminPerms, "Admin should have moderator perm: {$perm}");
        }
    }

    private function findRole(string $name): ?Role
    {
        foreach ($this->registeredRoles as $role) {
            if ($role->name === $name) {
                return $role;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function permissionNames(Role $role): array
    {
        return array_map(static fn($p): string => $p->name, $role->permissions);
    }
}
