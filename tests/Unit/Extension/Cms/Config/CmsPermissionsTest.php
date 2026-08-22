<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;

#[CoversClass(CmsPermissions::class)]
final class CmsPermissionsTest extends TestCase
{
    #[Test]
    public function registerRegistersNineRoles(): void
    {
        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))->method('register');

        CmsPermissions::register($registry);
    }

    #[Test]
    public function registeredRolesHaveCorrectNames(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $names = array_map(static fn(Role $r): string => $r->name, $registered);

        self::assertContains('cms.viewer', $names);
        self::assertContains('cms.contributor', $names);
        self::assertContains('cms.reviewer', $names);
        self::assertContains('cms.editor', $names);
        self::assertContains('cms.media_manager', $names);
        self::assertContains('cms.seo_manager', $names);
        self::assertContains('cms.shop_manager', $names);
        self::assertContains('cms.analytics_viewer', $names);
        self::assertContains('cms.admin', $names);
    }

    #[Test]
    public function viewerRoleHasNoPermissions(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $viewer = $this->findRole($registered, 'cms.viewer');
        self::assertNotNull($viewer);
        self::assertSame([], $viewer->permissions);
    }

    #[Test]
    public function contributorRoleHasContentAndMediaViewPermissions(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $contributor = $this->findRole($registered, 'cms.contributor');
        self::assertNotNull($contributor);

        $permissionNames = array_map(
            static fn(\Pulsar\Auth\Authorization\Permission $p): string => $p->name,
            $contributor->permissions,
        );

        self::assertContains('cms.content.view', $permissionNames);
        self::assertContains('cms.content.create', $permissionNames);
        self::assertContains('cms.dashboard.view', $permissionNames);
    }

    #[Test]
    public function adminRoleHasAllPermissions(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $admin = $this->findRole($registered, 'cms.admin');
        self::assertNotNull($admin);

        $permissionNames = array_map(
            static fn(\Pulsar\Auth\Authorization\Permission $p): string => $p->name,
            $admin->permissions,
        );

        self::assertContains('cms.content.view', $permissionNames);
        self::assertContains('cms.content.publish', $permissionNames);
        self::assertContains('cms.settings.manage', $permissionNames);
        self::assertContains('cms.themes.install', $permissionNames);
        self::assertContains('cms.backup.create', $permissionNames);
        self::assertContains('cms.products.view', $permissionNames);
        self::assertContains('cms.seo.manage', $permissionNames);
        self::assertContains('cms.search.view_analytics', $permissionNames);
        self::assertContains('cms.livecss.edit', $permissionNames);
    }

    #[Test]
    public function editorRoleInheritsContributorAndReviewerPermissions(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $editor = $this->findRole($registered, 'cms.editor');
        self::assertNotNull($editor);

        $permissionNames = array_map(
            static fn(\Pulsar\Auth\Authorization\Permission $p): string => $p->name,
            $editor->permissions,
        );

        // From contributor
        self::assertContains('cms.content.create', $permissionNames);
        // From reviewer
        self::assertContains('cms.content.approve', $permissionNames);
        // Editor-specific
        self::assertContains('cms.content.publish', $permissionNames);
        self::assertContains('cms.content.delete', $permissionNames);
        self::assertContains('cms.taxonomy.manage', $permissionNames);
    }

    #[Test]
    public function shopManagerRoleHasCommercePermissions(): void
    {
        /** @var list<Role> $registered */
        $registered = [];

        $registry = $this->createMock(RoleRegistryInterface::class);
        $registry->expects(self::exactly(9))
            ->method('register')
            ->willReturnCallback(function (Role $role) use (&$registered): void {
                $registered[] = $role;
            });

        CmsPermissions::register($registry);

        $shopManager = $this->findRole($registered, 'cms.shop_manager');
        self::assertNotNull($shopManager);

        $permissionNames = array_map(
            static fn(\Pulsar\Auth\Authorization\Permission $p): string => $p->name,
            $shopManager->permissions,
        );

        self::assertContains('cms.products.view', $permissionNames);
        self::assertContains('cms.orders.manage', $permissionNames);
        self::assertContains('cms.invoices.download', $permissionNames);
        self::assertContains('cms.digital_assets.manage', $permissionNames);
    }

    /**
     * @param list<Role> $roles
     */
    private function findRole(array $roles, string $name): ?Role
    {
        foreach ($roles as $role) {
            if ($role->name === $name) {
                return $role;
            }
        }

        return null;
    }
}
