<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;

/**
 * Benchmarks for authorization and security primitives.
 *
 * Covers value object creation (Permission, Role, PolicyContext),
 * and Gate authorization checks for allowed and denied cases.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class AuthorizationBench
{
    private Gate $gate;
    private Identity $allowedIdentity;
    private Identity $deniedIdentity;

    public function setUp(): void
    {
        $registry = new InMemoryRoleRegistry();

        $adminRole = new Role('admin', [
            new Permission('users.create'),
            new Permission('users.read'),
            new Permission('users.update'),
            new Permission('users.delete'),
            new Permission('reports.view'),
        ]);

        $viewerRole = new Role('viewer', [
            new Permission('reports.view'),
        ]);

        $registry->register($adminRole);
        $registry->register($viewerRole);

        $this->gate = new Gate($registry, ['super_admin']);

        $this->allowedIdentity = new Identity(
            id: 'user-1',
            displayName: 'Admin User',
            roles: ['admin'],
        );

        $this->deniedIdentity = new Identity(
            id: 'user-2',
            displayName: 'Viewer User',
            roles: ['viewer'],
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchPermissionCreation(): void
    {
        $_ = new Permission('users.create');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchRoleCreation(): void
    {
        $_ = new Role('editor', [
            new Permission('posts.create'),
            new Permission('posts.update'),
        ]);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchPolicyContextCreation(): void
    {
        $_ = new PolicyContext(
            permission: 'users.create',
            resource: 'user:42',
            attributes: ['department' => 'engineering', 'level' => 5],
        );
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchGateAllows(): void
    {
        $this->gate->allows($this->allowedIdentity, 'users.create');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchGateDenies(): void
    {
        $this->gate->denies($this->deniedIdentity, 'users.create');
    }
}
