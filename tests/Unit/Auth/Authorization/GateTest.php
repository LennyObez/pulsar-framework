<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;

#[CoversClass(Gate::class)]
final class GateTest extends TestCase
{
    private InMemoryRoleRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRoleRegistry();
    }

    #[Test]
    public function allowsReturnsFalseForIdentityWithoutMatchingPermission(): void
    {
        $role = new Role(
            name: 'viewer',
            permissions: [new Permission('reports.view')],
        );
        $this->registry->register($role);

        $gate = new Gate($this->registry);
        $identity = new Identity(id: 'user-1', displayName: 'Test', roles: ['viewer']);

        self::assertFalse($gate->allows($identity, 'users.delete'));
    }

    #[Test]
    public function allowsReturnsTrueWhenRbacGrantsPermission(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: [
                new Permission('posts.create'),
                new Permission('posts.update'),
            ],
        );
        $this->registry->register($role);

        $gate = new Gate($this->registry);
        $identity = new Identity(id: 'user-2', displayName: 'Editor', roles: ['editor']);

        self::assertTrue($gate->allows($identity, 'posts.create'));
        self::assertTrue($gate->allows($identity, 'posts.update'));
    }

    #[Test]
    public function allowsReturnsTrueForSuperRoleRegardlessOfPermissions(): void
    {
        $gate = new Gate($this->registry, superRoles: ['superadmin']);
        $identity = new Identity(id: 'user-3', displayName: 'Super', roles: ['superadmin']);

        self::assertTrue($gate->allows($identity, 'anything.at.all'));
        self::assertTrue($gate->allows($identity, 'users.delete'));
        self::assertTrue($gate->allows($identity, 'system.shutdown'));
    }

    #[Test]
    public function deniesReturnsOppositeOfAllows(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: [new Permission('posts.create')],
        );
        $this->registry->register($role);

        $gate = new Gate($this->registry);
        $identity = new Identity(id: 'user-4', displayName: 'Editor', roles: ['editor']);

        // allows posts.create => denies should be false
        self::assertFalse($gate->denies($identity, 'posts.create'));

        // does not allow users.delete => denies should be true
        self::assertTrue($gate->denies($identity, 'users.delete'));
    }

    #[Test]
    public function abacPolicyExplicitDenyOverridesRbacAllow(): void
    {
        $role = new Role(
            name: 'editor',
            permissions: [new Permission('posts.delete')],
        );
        $this->registry->register($role);

        $denyPolicy = $this->createMock(PolicyInterface::class);
        $denyPolicy->method('evaluate')
            ->willReturn(false);

        $gate = new Gate($this->registry);
        $gate->addPolicy($denyPolicy);

        $identity = new Identity(id: 'user-5', displayName: 'Editor', roles: ['editor']);

        // RBAC would allow posts.delete, but the ABAC policy explicitly denies
        self::assertFalse($gate->allows($identity, 'posts.delete'));
    }

    #[Test]
    public function abacPolicyExplicitAllowCanGrantWithoutRbac(): void
    {
        // No roles registered, so RBAC has no permissions
        $allowPolicy = $this->createMock(PolicyInterface::class);
        $allowPolicy->method('evaluate')
            ->willReturn(true);

        $gate = new Gate($this->registry);
        $gate->addPolicy($allowPolicy);

        $identity = new Identity(id: 'user-6', displayName: 'NoRoles', roles: []);

        // No RBAC permission, but ABAC policy explicitly allows
        self::assertTrue($gate->allows($identity, 'special.access'));
    }

    #[Test]
    public function policyAbstainHasNoEffect(): void
    {
        $role = new Role(
            name: 'viewer',
            permissions: [new Permission('reports.view')],
        );
        $this->registry->register($role);

        $abstainPolicy = $this->createMock(PolicyInterface::class);
        $abstainPolicy->method('evaluate')
            ->willReturn(null);

        $gate = new Gate($this->registry);
        $gate->addPolicy($abstainPolicy);

        $identity = new Identity(id: 'user-7', displayName: 'Viewer', roles: ['viewer']);

        // Abstaining policy should not affect RBAC result
        self::assertTrue($gate->allows($identity, 'reports.view'));
        self::assertFalse($gate->allows($identity, 'users.delete'));
    }
}
