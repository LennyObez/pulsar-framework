<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Policy\AdminResourcePolicy;

#[CoversClass(AdminResourcePolicy::class)]
final class AdminResourcePolicyTest extends TestCase
{
    private AdminConfig $config;

    protected function setUp(): void
    {
        $this->config = AdminConfig::fromArray([]);
    }

    #[Test]
    public function returnsNullForNonAdminPermission(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $context = new PolicyContext(permission: 'posts.create');

        self::assertNull($policy->evaluate($identity, $context));
    }

    #[Test]
    public function returnsFalseForUnauthenticatedIdentity(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);
        $context = new PolicyContext(permission: 'admin.access');

        self::assertFalse($policy->evaluate($identity, $context));
    }

    #[Test]
    public function returnsFalseForIdentityWithoutRequiredRole(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturn(false);
        $context = new PolicyContext(permission: 'admin.access');

        self::assertFalse($policy->evaluate($identity, $context));
    }

    #[Test]
    public function returnsTrueForAccessPanelWithRequiredRole(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturn(true);
        $context = new PolicyContext(permission: 'admin.access');

        self::assertTrue($policy->evaluate($identity, $context));
    }

    #[Test]
    public function returnsTrueForDashboardWithRequiredRole(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturn(true);
        $context = new PolicyContext(permission: 'admin.dashboard');

        self::assertTrue($policy->evaluate($identity, $context));
    }

    #[Test]
    #[DataProvider('adminRolePermissionsProvider')]
    public function managePermissionsRequireAdminRole(string $permission): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        // hasRole returns true for all role checks
        $identity->method('hasRole')->willReturn(true);
        $context = new PolicyContext(permission: $permission);

        self::assertTrue($policy->evaluate($identity, $context));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminRolePermissionsProvider(): iterable
    {
        yield 'manage resources' => ['admin.resources.manage'];
        yield 'export data' => ['admin.export'];
        yield 'view audit log' => ['admin.audit.view'];
    }

    #[Test]
    public function manageSettingsRequiresSuperAdmin(): void
    {
        $policy = new AdminResourcePolicy($this->config);

        // Identity that has 'admin' role but not 'super_admin'
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturnCallback(
            static fn(string $role): bool => $role === 'admin',
        );
        $context = new PolicyContext(permission: 'admin.settings');

        // 'admin' role matches requiredRole check, but ManageSettings needs 'super_admin'
        self::assertFalse($policy->evaluate($identity, $context));
    }

    #[Test]
    public function schemaPermissionsReturnNull(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturn(true);
        $context = new PolicyContext(permission: 'admin.schema.view');

        self::assertNull($policy->evaluate($identity, $context));
    }

    #[Test]
    public function returnsNullForUnknownAdminPermission(): void
    {
        $policy = new AdminResourcePolicy($this->config);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('hasRole')->willReturn(true);
        $context = new PolicyContext(permission: 'admin.nonexistent');

        self::assertNull($policy->evaluate($identity, $context));
    }
}
