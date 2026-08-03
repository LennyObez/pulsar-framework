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
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;
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
    public function contributorCannotPublishContent(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.publish'));
    }

    #[Test]
    public function contributorCannotArchiveContent(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.archive'));
    }

    #[Test]
    public function contributorCannotDeleteContent(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.delete'));
    }

    // -- Viewer cannot access admin routes ----------------------------------

    #[Test]
    public function viewerCannotAccessDashboard(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.dashboard.view'));
    }

    #[Test]
    public function viewerCannotViewContent(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.content.view'));
    }

    #[Test]
    public function viewerCannotManageUsers(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.users.manage'));
    }

    #[Test]
    public function viewerCannotInstallPlugins(): void
    {
        $viewer = $this->roles['cms.viewer'];

        self::assertFalse($viewer->hasPermission('cms.plugins.install'));
    }

    // -- Editor cannot install themes ---------------------------------------

    #[Test]
    public function editorCannotInstallThemes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.install'));
    }

    #[Test]
    public function editorCannotManageThemes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.manage'));
    }

    #[Test]
    public function editorCannotDeleteThemes(): void
    {
        $editor = $this->roles['cms.editor'];

        self::assertFalse($editor->hasPermission('cms.themes.delete'));
    }

    // -- Gate denies → controller rejects -----------------------------------

    #[Test]
    public function gateDeniesPermissionThrowsException(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $lockStub = $this->createStub(LockInterface::class);
        $lockStub->method('acquire')->willReturn(new LockHandle('r', 't', 1.0, 60));
        $lockStub->method('release')->willReturn(true);
        $rateLimiter = new CmsRateLimiter($this->createStub(TaggedCacheInterface::class), $lockStub);

        $controller = new TwoFactorController(
            new TotpGenerator(),
            new TotpVerifier(new TotpGenerator()),
            new RecoveryCodeGenerator(),
            new QrCodeEncoder(),
            $rateLimiter,
            null,
            $gate,
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
        $this->expectExceptionMessageIsOrContains('Permission denied');

        $controller->enroll($request);
    }

    // -- Cross-role boundary enforcement ------------------------------------

    #[Test]
    public function mediaManagerCannotManageContent(): void
    {
        $mediaManager = $this->roles['cms.media_manager'];

        self::assertFalse($mediaManager->hasPermission('cms.content.create'));
        self::assertFalse($mediaManager->hasPermission('cms.content.edit'));
        self::assertFalse($mediaManager->hasPermission('cms.content.publish'));
        self::assertFalse($mediaManager->hasPermission('cms.content.delete'));
    }

    #[Test]
    public function seoManagerCannotManagePlugins(): void
    {
        $seo = $this->roles['cms.seo_manager'];

        self::assertFalse($seo->hasPermission('cms.plugins.install'));
        self::assertFalse($seo->hasPermission('cms.plugins.manage'));
        self::assertFalse($seo->hasPermission('cms.plugins.delete'));
    }

    #[Test]
    public function shopManagerCannotManageThemes(): void
    {
        $shop = $this->roles['cms.shop_manager'];

        self::assertFalse($shop->hasPermission('cms.themes.install'));
        self::assertFalse($shop->hasPermission('cms.themes.manage'));
        self::assertFalse($shop->hasPermission('cms.themes.delete'));
    }

    #[Test]
    public function analyticsViewerHasMinimalPermissions(): void
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
    public function noRoleHasWildcardPermission(): void
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
    public function contributorCannotApproveContent(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.approve'));
    }

    #[Test]
    public function contributorCannotForceUnlock(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.content.force_unlock'));
    }

    #[Test]
    public function contributorCannotModerateComments(): void
    {
        $contributor = $this->roles['cms.contributor'];

        self::assertFalse($contributor->hasPermission('cms.comments.moderate'));
    }

    // -- Reviewer cannot escalate to editor permissions --------------------

    #[Test]
    public function reviewerCannotEditAllContent(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertFalse($reviewer->hasPermission('cms.content.edit'));
    }

    #[Test]
    public function reviewerCannotRestoreContent(): void
    {
        $reviewer = $this->roles['cms.reviewer'];

        self::assertFalse($reviewer->hasPermission('cms.content.restore'));
    }
}
