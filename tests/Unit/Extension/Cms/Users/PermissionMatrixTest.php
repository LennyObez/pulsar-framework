<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Users;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;

use function array_map;
use function count;
use function in_array;

#[CoversClass(CmsPermissions::class)]
final class PermissionMatrixTest extends TestCase
{
    /** @var array<string, Role> */
    private array $roles = [];

    protected function setUp(): void
    {
        $registry = new class implements RoleRegistryInterface {
            /** @var array<string, Role> */
            public array $roles = [];

            public function findByName(string $name): ?Role
            {
                return $this->roles[$name] ?? null;
            }

            public function permissionsForRoles(array $roleNames): array
            {
                $permissions = [];

                foreach ($roleNames as $name) {
                    if (isset($this->roles[$name])) {
                        foreach ($this->roles[$name]->permissions as $p) {
                            $permissions[] = $p;
                        }
                    }
                }

                return $permissions;
            }

            public function register(Role $role): void
            {
                $this->roles[$role->name] = $role;
            }
        };

        CmsPermissions::register($registry);
        $this->roles = $registry->roles;
    }

    // -- Viewer role --------------------------------------------------------

    #[Test]
    public function viewerHasNoPermissions(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertSame([], $viewer->permissions);
    }

    // -- Contributor role ---------------------------------------------------

    #[Test]
    public function contributorCanCreateAndEditOwnContent(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertTrue($contributor->hasPermission('cms.content.create'));
        self::assertTrue($contributor->hasPermission('cms.content.edit_own'));
        self::assertTrue($contributor->hasPermission('cms.content.view'));
    }

    #[Test]
    public function contributorCannotPublish(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.publish'));
    }

    #[Test]
    public function contributorCannotApprove(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.approve'));
    }

    #[Test]
    public function contributorCannotInstallThemes(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.themes.install'));
    }

    #[Test]
    public function contributorCannotManagePlugins(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.plugins.install'));
        self::assertFalse($contributor->hasPermission('cms.plugins.manage'));
    }

    // -- Reviewer role ------------------------------------------------------

    #[Test]
    public function reviewerInheritsContributorPermissions(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        // Contributor permissions should be present
        self::assertTrue($reviewer->hasPermission('cms.content.create'));
        self::assertTrue($reviewer->hasPermission('cms.content.edit_own'));
        self::assertTrue($reviewer->hasPermission('cms.content.submit_review'));
    }

    #[Test]
    public function reviewerCanApprove(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertTrue($reviewer->hasPermission('cms.content.approve'));
    }

    #[Test]
    public function reviewerCannotPublish(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertFalse($reviewer->hasPermission('cms.content.publish'));
    }

    // -- Editor role --------------------------------------------------------

    #[Test]
    public function editorInheritsReviewerPermissions(): void
    {
        $editor = $this->roles['cms.editor'];

        // Contributor
        self::assertTrue($editor->hasPermission('cms.content.create'));
        self::assertTrue($editor->hasPermission('cms.content.edit_own'));

        // Reviewer
        self::assertTrue($editor->hasPermission('cms.content.approve'));
    }

    #[Test]
    public function editorCanPublishAndArchive(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertTrue($editor->hasPermission('cms.content.publish'));
        self::assertTrue($editor->hasPermission('cms.content.archive'));
        self::assertTrue($editor->hasPermission('cms.content.edit'));
    }

    #[Test]
    public function editorCannotInstallThemes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.install'));
    }

    #[Test]
    public function editorCannotManagePlugins(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.plugins.install'));
    }

    #[Test]
    public function editorCannotManageSettings(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.settings.manage'));
    }

    // -- Role inheritance chain: contributor subset reviewer subset editor subset admin

    #[Test]
    public function contributorIsSubsetOfReviewer(): void
    {
        $contributor = $this->roles['cms.contributor'];
        $reviewer = $this->roles['cms.reviewer'];

        $contributorPerms = $this->permissionNames($contributor);
        $reviewerPerms = $this->permissionNames($reviewer);

        foreach ($contributorPerms as $perm) {
            self::assertTrue(
                in_array($perm, $reviewerPerms, true),
                "Reviewer should have contributor permission: {$perm}",
            );
        }

        // Reviewer must have at least one permission contributor doesn't
        self::assertGreaterThan(
            count($contributorPerms),
            count($reviewerPerms),
            'Reviewer should have more permissions than contributor',
        );
    }

    #[Test]
    public function reviewerIsSubsetOfEditor(): void
    {
        $reviewer = $this->roles['cms.reviewer'];
        $editor = $this->roles['cms.editor'];

        $reviewerPerms = $this->permissionNames($reviewer);
        $editorPerms = $this->permissionNames($editor);

        foreach ($reviewerPerms as $perm) {
            self::assertTrue(
                in_array($perm, $editorPerms, true),
                "Editor should have reviewer permission: {$perm}",
            );
        }

        self::assertGreaterThan(
            count($reviewerPerms),
            count($editorPerms),
            'Editor should have more permissions than reviewer',
        );
    }

    #[Test]
    public function editorIsSubsetOfAdmin(): void
    {
        $editor = $this->roles['cms.editor'];
        $admin = $this->roles['cms.admin'];

        $editorPerms = $this->permissionNames($editor);
        $adminPerms = $this->permissionNames($admin);

        foreach ($editorPerms as $perm) {
            self::assertTrue(
                in_array($perm, $adminPerms, true),
                "Admin should have editor permission: {$perm}",
            );
        }

        self::assertGreaterThan(
            count($editorPerms),
            count($adminPerms),
            'Admin should have more permissions than editor',
        );
    }

    // -- Admin role ---------------------------------------------------------

    #[Test]
    public function adminHasAllPermissions(): void
    {
        $admin = $this->roles['cms.admin'];

        // Spot-check across all permission groups
        self::assertTrue($admin->hasPermission('cms.dashboard.view'));
        self::assertTrue($admin->hasPermission('cms.content.publish'));
        self::assertTrue($admin->hasPermission('cms.content.approve'));
        self::assertTrue($admin->hasPermission('cms.content.archive'));
        self::assertTrue($admin->hasPermission('cms.media.upload'));
        self::assertTrue($admin->hasPermission('cms.media.delete'));
        self::assertTrue($admin->hasPermission('cms.seo.manage'));
        self::assertTrue($admin->hasPermission('cms.themes.install'));
        self::assertTrue($admin->hasPermission('cms.themes.manage'));
        self::assertTrue($admin->hasPermission('cms.plugins.install'));
        self::assertTrue($admin->hasPermission('cms.plugins.manage'));
        self::assertTrue($admin->hasPermission('cms.settings.manage'));
        self::assertTrue($admin->hasPermission('cms.users.manage'));
        self::assertTrue($admin->hasPermission('cms.export'));
        self::assertTrue($admin->hasPermission('cms.import'));
        self::assertTrue($admin->hasPermission('cms.orders.manage'));
        self::assertTrue($admin->hasPermission('cms.search.view_analytics'));
        self::assertTrue($admin->hasPermission('cms.livecss.edit'));
    }

    // -- Specialized roles: no leakage between non-inheriting roles ---------

    #[Test]
    public function mediaManagerHasNoContentPublish(): void
    {
        $mediaManager = $this->roles['cms.media_manager'];

        self::assertTrue($mediaManager->hasPermission('cms.media.upload'));
        self::assertTrue($mediaManager->hasPermission('cms.media.delete'));
        self::assertFalse($mediaManager->hasPermission('cms.content.create'));
        self::assertFalse($mediaManager->hasPermission('cms.content.publish'));
        self::assertFalse($mediaManager->hasPermission('cms.content.edit'));
    }

    #[Test]
    public function seoManagerHasNoContentPermissions(): void
    {
        $seoManager = $this->roles['cms.seo_manager'];

        self::assertTrue($seoManager->hasPermission('cms.seo.view'));
        self::assertTrue($seoManager->hasPermission('cms.seo.manage'));
        self::assertFalse($seoManager->hasPermission('cms.content.create'));
        self::assertFalse($seoManager->hasPermission('cms.content.publish'));
    }

    #[Test]
    public function shopManagerHasNoContentPermissions(): void
    {
        $shopManager = $this->roles['cms.shop_manager'];

        self::assertTrue($shopManager->hasPermission('cms.orders.manage'));
        self::assertTrue($shopManager->hasPermission('cms.products.edit'));
        self::assertFalse($shopManager->hasPermission('cms.content.create'));
        self::assertFalse($shopManager->hasPermission('cms.content.publish'));
        self::assertFalse($shopManager->hasPermission('cms.themes.install'));
    }

    #[Test]
    public function analyticsViewerHasOnlyAnalyticsPermissions(): void
    {
        $analytics = $this->roles['cms.analytics_viewer'];

        self::assertTrue($analytics->hasPermission('cms.search.view_analytics'));
        self::assertFalse($analytics->hasPermission('cms.content.create'));
        self::assertFalse($analytics->hasPermission('cms.seo.manage'));
        self::assertFalse($analytics->hasPermission('cms.plugins.install'));
    }

    #[Test]
    public function mediaManagerHasNoSeoPermissions(): void
    {
        $mediaManager = $this->roles['cms.media_manager'];

        self::assertFalse($mediaManager->hasPermission('cms.seo.view'));
        self::assertFalse($mediaManager->hasPermission('cms.seo.manage'));
    }

    #[Test]
    public function seoManagerHasNoMediaPermissions(): void
    {
        $seoManager = $this->roles['cms.seo_manager'];

        self::assertFalse($seoManager->hasPermission('cms.media.upload'));
        self::assertFalse($seoManager->hasPermission('cms.media.delete'));
    }

    // -- All expected roles are registered -----------------------------------

    #[Test]
    public function allExpectedRolesRegistered(): void
    {
        $expectedRoles = [
            'cms.viewer',
            'cms.contributor',
            'cms.reviewer',
            'cms.editor',
            'cms.media_manager',
            'cms.seo_manager',
            'cms.shop_manager',
            'cms.analytics_viewer',
            'cms.admin',
        ];

        foreach ($expectedRoles as $roleName) {
            self::assertArrayHasKey(
                $roleName,
                $this->roles,
                "Role {$roleName} should be registered",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function permissionNames(Role $role): array
    {
        return array_map(
            static fn($p) => $p->name,
            $role->permissions,
        );
    }
}
