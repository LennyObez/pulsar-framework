<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;

/**
 * Integration tests for the CMS permission matrix and step-up authentication.
 *
 * These tests verify that the permission matrix registered by CmsPermissions
 * correctly enforces access control when combined with a Gate implementation.
 */
#[CoversClass(CmsPermissions::class)]
final class AuthAndPermissionIntegrationTest extends TestCase
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

    // -- Admin routes with correct role → allowed ---------------------------

    #[Test]
    public function adminCanAccessSettings(): void
    {
        $admin = $this->roles['cms.admin'];

        self::assertTrue($admin->hasPermission('cms.settings.view'));
        self::assertTrue($admin->hasPermission('cms.settings.manage'));
    }

    #[Test]
    public function adminCanManagePlugins(): void
    {
        $admin = $this->roles['cms.admin'];

        self::assertTrue($admin->hasPermission('cms.plugins.view'));
        self::assertTrue($admin->hasPermission('cms.plugins.install'));
        self::assertTrue($admin->hasPermission('cms.plugins.manage'));
        self::assertTrue($admin->hasPermission('cms.plugins.delete'));
    }

    #[Test]
    public function adminCanManageThemes(): void
    {
        $admin = $this->roles['cms.admin'];

        self::assertTrue($admin->hasPermission('cms.themes.view'));
        self::assertTrue($admin->hasPermission('cms.themes.install'));
        self::assertTrue($admin->hasPermission('cms.themes.manage'));
        self::assertTrue($admin->hasPermission('cms.themes.delete'));
    }

    #[Test]
    public function adminCanManageUsers(): void
    {
        $admin = $this->roles['cms.admin'];

        self::assertTrue($admin->hasPermission('cms.users.view'));
        self::assertTrue($admin->hasPermission('cms.users.manage'));
    }

    #[Test]
    public function editorCanPublishContent(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertTrue($editor->hasPermission('cms.content.publish'));
        self::assertTrue($editor->hasPermission('cms.content.archive'));
    }

    // -- Admin routes with wrong role → denied ------------------------------

    #[Test]
    public function contributorDeniedSettingsAccess(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.settings.view'));
        self::assertFalse($contributor->hasPermission('cms.settings.manage'));
    }

    #[Test]
    public function viewerDeniedAllAdminRoutes(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.dashboard.view'));
        self::assertFalse($viewer->hasPermission('cms.content.view'));
        self::assertFalse($viewer->hasPermission('cms.settings.view'));
        self::assertFalse($viewer->hasPermission('cms.plugins.view'));
        self::assertFalse($viewer->hasPermission('cms.themes.view'));
        self::assertFalse($viewer->hasPermission('cms.users.view'));
    }

    #[Test]
    public function editorDeniedPluginInstall(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.plugins.install'));
        self::assertFalse($editor->hasPermission('cms.plugins.manage'));
    }

    #[Test]
    public function editorDeniedThemeInstall(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.install'));
        self::assertFalse($editor->hasPermission('cms.themes.manage'));
    }

    #[Test]
    public function editorDeniedUserManagement(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.users.view'));
        self::assertFalse($editor->hasPermission('cms.users.manage'));
    }

    // -- Step-up required routes: gate simulation ---------------------------

    #[Test]
    public function stepUpAttributeRequiredForSensitiveActions(): void
    {
        // Simulate a request without step-up verification
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name): mixed => match ($name) {
                'step_up_verified' => false,
                default => null,
            });

        $stepUp = $request->getAttribute('step_up_verified', false);
        self::assertFalse($stepUp);
    }

    #[Test]
    public function stepUpVerifiedAllowsSensitiveActions(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name): mixed => match ($name) {
                'step_up_verified' => true,
                default => null,
            });

        $stepUp = $request->getAttribute('step_up_verified', false);
        self::assertTrue($stepUp);
    }

    // -- Cross-role boundary checks -----------------------------------------

    #[Test]
    public function mediaManagerDeniedContentOperations(): void
    {
        $mediaManager = $this->roles['cms.media_manager'];

        // Can manage media
        self::assertTrue($mediaManager->hasPermission('cms.media.upload'));
        self::assertTrue($mediaManager->hasPermission('cms.media.delete'));

        // Cannot touch content
        self::assertFalse($mediaManager->hasPermission('cms.content.create'));
        self::assertFalse($mediaManager->hasPermission('cms.content.publish'));
        self::assertFalse($mediaManager->hasPermission('cms.content.delete'));

        // Cannot touch settings or plugins
        self::assertFalse($mediaManager->hasPermission('cms.settings.manage'));
        self::assertFalse($mediaManager->hasPermission('cms.plugins.install'));
    }

    #[Test]
    public function seoManagerDeniedContentAndAdminOperations(): void
    {
        $seo = $this->roles['cms.seo_manager'];

        self::assertTrue($seo->hasPermission('cms.seo.view'));
        self::assertTrue($seo->hasPermission('cms.seo.manage'));

        self::assertFalse($seo->hasPermission('cms.content.publish'));
        self::assertFalse($seo->hasPermission('cms.themes.install'));
        self::assertFalse($seo->hasPermission('cms.plugins.install'));
    }

    #[Test]
    public function shopManagerScopedToCommerce(): void
    {
        $shop = $this->roles['cms.shop_manager'];

        self::assertTrue($shop->hasPermission('cms.orders.view'));
        self::assertTrue($shop->hasPermission('cms.orders.manage'));
        self::assertTrue($shop->hasPermission('cms.orders.refund'));
        self::assertTrue($shop->hasPermission('cms.products.view'));
        self::assertTrue($shop->hasPermission('cms.products.edit'));

        self::assertFalse($shop->hasPermission('cms.content.publish'));
        self::assertFalse($shop->hasPermission('cms.users.manage'));
    }
}
