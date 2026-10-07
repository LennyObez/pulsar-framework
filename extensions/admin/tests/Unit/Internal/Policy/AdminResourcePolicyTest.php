<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Policy\AdminResourcePolicy;

use function in_array;

#[CoversClass(AdminResourcePolicy::class)]
final class AdminResourcePolicyTest extends TestCase
{
    #[Test]
    public function nonAdminPermissionReturnsNull(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertNull($policy->evaluate($identity, new PolicyContext('users.view')));
    }

    #[Test]
    public function unauthenticatedUserDenied(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: false);

        self::assertFalse($policy->evaluate($identity, new PolicyContext('admin.access')));
    }

    #[Test]
    public function missingRequiredRoleDenied(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['user']);

        self::assertFalse($policy->evaluate($identity, new PolicyContext('admin.access')));
    }

    #[Test]
    public function accessPanelAllowedForAdmin(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertTrue($policy->evaluate($identity, new PolicyContext('admin.access')));
    }

    #[Test]
    public function viewDashboardAllowedForAdmin(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertTrue($policy->evaluate($identity, new PolicyContext('admin.dashboard')));
    }

    #[Test]
    #[DataProvider('adminRolePermissionsProvider')]
    public function managePermissionsRequireAdminRole(string $permission): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertTrue($policy->evaluate($identity, new PolicyContext($permission)));
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
        $policy = $this->createPolicy();

        $adminIdentity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $superAdminIdentity = $this->createIdentity(authenticated: true, roles: ['admin', 'super_admin']);

        self::assertFalse($policy->evaluate($adminIdentity, new PolicyContext('admin.settings')));
        self::assertTrue($policy->evaluate($superAdminIdentity, new PolicyContext('admin.settings')));
    }

    #[Test]
    public function schemaPermissionsReturnNull(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.schema.view')));
        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.schema.create')));
        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.schema.alter')));
        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.schema.drop')));
        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.schema.rename')));
    }

    #[Test]
    public function invalidAdminPermissionReturnsNull(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);

        self::assertNull($policy->evaluate($identity, new PolicyContext('admin.unknown.permission')));
    }

    #[Test]
    public function customRequiredRole(): void
    {
        $config = AdminConfig::fromArray(['security' => ['required_role' => 'manager']]);
        $policy = new AdminResourcePolicy($config);

        $adminIdentity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $managerIdentity = $this->createIdentity(authenticated: true, roles: ['manager', 'admin']);

        self::assertFalse($policy->evaluate($adminIdentity, new PolicyContext('admin.access')));
        self::assertTrue($policy->evaluate($managerIdentity, new PolicyContext('admin.access')));
    }

    private function createPolicy(): AdminResourcePolicy
    {
        return new AdminResourcePolicy(AdminConfig::fromArray([]));
    }

    /**
     * @param list<string> $roles
     */
    private function createIdentity(bool $authenticated, array $roles = []): IdentityInterface&Stub
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn($authenticated);
        $identity->method('hasRole')->willReturnCallback(
            static fn(string $role): bool => in_array($role, $roles, true),
        );

        return $identity;
    }
}
