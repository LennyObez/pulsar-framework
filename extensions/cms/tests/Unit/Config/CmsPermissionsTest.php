<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;

#[CoversClass(CmsPermissions::class)]
final class CmsPermissionsTest extends TestCase
{
    #[Test]
    public function registerCreatesNineRoles(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        self::assertCount(9, $roles);
        self::assertArrayHasKey('cms.viewer', $roles);
        self::assertArrayHasKey('cms.contributor', $roles);
        self::assertArrayHasKey('cms.reviewer', $roles);
        self::assertArrayHasKey('cms.editor', $roles);
        self::assertArrayHasKey('cms.media_manager', $roles);
        self::assertArrayHasKey('cms.seo_manager', $roles);
        self::assertArrayHasKey('cms.shop_manager', $roles);
        self::assertArrayHasKey('cms.analytics_viewer', $roles);
        self::assertArrayHasKey('cms.admin', $roles);
    }

    #[Test]
    public function viewerRoleHasNoPermissions(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        self::assertSame([], $roles['cms.viewer']->permissions);
    }

    #[Test]
    public function contributorIncludesContentAndViewPermissions(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        $permissionNames = array_map(
            static fn(Permission $p): string => $p->name,
            $roles['cms.contributor']->permissions,
        );

        self::assertContains('cms.content.create', $permissionNames);
        self::assertContains('cms.content.view', $permissionNames);
        self::assertContains('cms.dashboard.view', $permissionNames);
    }

    #[Test]
    public function editorIncludesPublishAndModeratePermissions(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        $permissionNames = array_map(
            static fn(Permission $p): string => $p->name,
            $roles['cms.editor']->permissions,
        );

        self::assertContains('cms.content.publish', $permissionNames);
        self::assertContains('cms.content.archive', $permissionNames);
        self::assertContains('cms.comments.moderate', $permissionNames);
    }

    #[Test]
    public function adminIncludesAllPermissions(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        $permissionNames = array_map(
            static fn(Permission $p): string => $p->name,
            $roles['cms.admin']->permissions,
        );

        self::assertContains('cms.themes.install', $permissionNames);
        self::assertContains('cms.plugins.manage', $permissionNames);
        self::assertContains('cms.settings.manage', $permissionNames);
        self::assertContains('cms.backup.create', $permissionNames);
        self::assertContains('cms.products.edit', $permissionNames);
        self::assertContains('cms.orders.refund', $permissionNames);
    }

    #[Test]
    public function shopManagerHasCommercePermissions(): void
    {
        $registry = $this->createStub(RoleRegistryInterface::class);

        $roles = [];
        $registry->method('register')->willReturnCallback(function (Role $role) use (&$roles): void {
            $roles[$role->name] = $role;
        });

        CmsPermissions::register($registry);

        $permissionNames = array_map(
            static fn(Permission $p): string => $p->name,
            $roles['cms.shop_manager']->permissions,
        );

        self::assertContains('cms.products.view', $permissionNames);
        self::assertContains('cms.orders.manage', $permissionNames);
        self::assertContains('cms.promotions.manage', $permissionNames);
        self::assertContains('cms.invoices.download', $permissionNames);
    }
}
