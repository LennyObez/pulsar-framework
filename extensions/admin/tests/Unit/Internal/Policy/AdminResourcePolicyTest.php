<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Policy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Policy\AdminResourcePolicy;

use function in_array;

final class AdminResourcePolicyTest extends TestCase
{
    #[Test]
    public function non_admin_permission_returns_null(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $context = new PolicyContext('users.view');

        self::assertNull($policy->evaluate($identity, $context));
    }

    #[Test]
    public function unauthenticated_user_denied(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: false);
        $context = new PolicyContext('admin.access');

        self::assertFalse($policy->evaluate($identity, $context));
    }

    #[Test]
    public function missing_required_role_denied(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['user']);
        $context = new PolicyContext('admin.access');

        self::assertFalse($policy->evaluate($identity, $context));
    }

    #[Test]
    public function access_panel_allowed_for_admin(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $context = new PolicyContext('admin.access');

        self::assertTrue($policy->evaluate($identity, $context));
    }

    #[Test]
    public function view_dashboard_allowed_for_admin(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $context = new PolicyContext('admin.dashboard');

        self::assertTrue($policy->evaluate($identity, $context));
    }

    #[Test]
    public function manage_resources_requires_admin_role(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $context = new PolicyContext('admin.resources.manage');

        self::assertTrue($policy->evaluate($identity, $context));
    }

    #[Test]
    public function manage_settings_requires_super_admin(): void
    {
        $policy = $this->createPolicy();

        $adminIdentity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $superAdminIdentity = $this->createIdentity(authenticated: true, roles: ['admin', 'super_admin']);

        self::assertFalse($policy->evaluate($adminIdentity, new PolicyContext('admin.settings')));
        self::assertTrue($policy->evaluate($superAdminIdentity, new PolicyContext('admin.settings')));
    }

    #[Test]
    public function schema_permissions_return_null(): void
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
    public function invalid_admin_permission_returns_null(): void
    {
        $policy = $this->createPolicy();
        $identity = $this->createIdentity(authenticated: true, roles: ['admin']);
        $context = new PolicyContext('admin.unknown.permission');

        self::assertNull($policy->evaluate($identity, $context));
    }

    #[Test]
    public function custom_required_role(): void
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
