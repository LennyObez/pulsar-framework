<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Extension\Cms\Config\CmsPermissions;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use RuntimeException;

/**
 * Security tests verifying privilege escalation is impossible.
 *
 * These tests ensure that users with lower privilege roles cannot
 * access administrative functions, install themes/plugins, or perform
 * operations outside their granted permissions.
 */
#[CoversClass(CmsPermissions::class)]
#[CoversClass(TwoFactorController::class)]
final class PrivilegeEscalationTest extends TestCase
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

    // -- Contributor cannot publish ------------------------------------------

    #[Test]
    public function test_contributor_cannot_publish_content(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.publish'));
    }

    #[Test]
    public function test_contributor_cannot_archive_content(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.archive'));
    }

    #[Test]
    public function test_contributor_cannot_delete_content(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.delete'));
    }

    // -- Viewer cannot access admin routes ----------------------------------

    #[Test]
    public function test_viewer_cannot_access_dashboard(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.dashboard.view'));
    }

    #[Test]
    public function test_viewer_cannot_view_content(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.content.view'));
    }

    #[Test]
    public function test_viewer_cannot_manage_users(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.users.manage'));
    }

    #[Test]
    public function test_viewer_cannot_install_plugins(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.plugins.install'));
    }

    // -- Editor cannot install themes ---------------------------------------

    #[Test]
    public function test_editor_cannot_install_themes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.install'));
    }

    #[Test]
    public function test_editor_cannot_manage_themes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.manage'));
    }

    #[Test]
    public function test_editor_cannot_delete_themes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.delete'));
    }

    // -- Gate denies → controller rejects -----------------------------------

    #[Test]
    public function test_gate_denies_permission_throws_exception(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new TwoFactorController(
            new TotpGenerator(),
            new TotpVerifier(new TotpGenerator()),
            new RecoveryCodeGenerator(),
            new QrCodeEncoder(),
            $gate,
            null,
        );

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('contributor-1');
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getAttribute')
            ->willReturnCallback(static fn(string $name) => match ($name) {
                'identity' => $identity,
                'step_up_verified' => true,
                default => null,
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Permission denied');

        $controller->enroll($request);
    }

    // -- Cross-role boundary enforcement ------------------------------------

    #[Test]
    public function test_media_manager_cannot_manage_content(): void
    {
        $mediaManager = $this->roles['cms.media_manager'];

        self::assertFalse($mediaManager->hasPermission('cms.content.create'));
        self::assertFalse($mediaManager->hasPermission('cms.content.edit'));
        self::assertFalse($mediaManager->hasPermission('cms.content.publish'));
        self::assertFalse($mediaManager->hasPermission('cms.content.delete'));
    }

    #[Test]
    public function test_seo_manager_cannot_manage_plugins(): void
    {
        $seo = $this->roles['cms.seo_manager'];

        self::assertFalse($seo->hasPermission('cms.plugins.install'));
        self::assertFalse($seo->hasPermission('cms.plugins.manage'));
        self::assertFalse($seo->hasPermission('cms.plugins.delete'));
    }

    #[Test]
    public function test_shop_manager_cannot_manage_themes(): void
    {
        $shop = $this->roles['cms.shop_manager'];

        self::assertFalse($shop->hasPermission('cms.themes.install'));
        self::assertFalse($shop->hasPermission('cms.themes.manage'));
        self::assertFalse($shop->hasPermission('cms.themes.delete'));
    }

    #[Test]
    public function test_analytics_viewer_has_minimal_permissions(): void
    {
        $analytics = $this->roles['cms.analytics_viewer'];

        // Has only analytics
        self::assertTrue($analytics->hasPermission('cms.search.view_analytics'));

        // Cannot do anything else
        self::assertFalse($analytics->hasPermission('cms.content.create'));
        self::assertFalse($analytics->hasPermission('cms.media.upload'));
        self::assertFalse($analytics->hasPermission('cms.seo.manage'));
        self::assertFalse($analytics->hasPermission('cms.themes.install'));
        self::assertFalse($analytics->hasPermission('cms.plugins.install'));
        self::assertFalse($analytics->hasPermission('cms.settings.manage'));
        self::assertFalse($analytics->hasPermission('cms.users.manage'));
    }

    // -- No wildcard permissions for any role --------------------------------

    #[Test]
    public function test_no_role_has_wildcard_permission(): void
    {
        foreach ($this->roles as $roleName => $role) {
            foreach ($role->permissions as $permission) {
                self::assertNotSame(
                    '*',
                    $permission->name,
                    "Role {$roleName} should not have wildcard (*) permission",
                );
            }
        }
    }

    // -- Contributor cannot escalate to reviewer/editor permissions ----------

    #[Test]
    public function test_contributor_cannot_approve_content(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.approve'));
    }

    #[Test]
    public function test_contributor_cannot_force_unlock(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.force_unlock'));
    }

    #[Test]
    public function test_contributor_cannot_moderate_comments(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.comments.moderate'));
    }

    // -- Reviewer cannot escalate to editor permissions --------------------

    #[Test]
    public function test_reviewer_cannot_edit_all_content(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertFalse($reviewer->hasPermission('cms.content.edit'));
    }

    #[Test]
    public function test_reviewer_cannot_restore_content(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertFalse($reviewer->hasPermission('cms.content.restore'));
    }
}
