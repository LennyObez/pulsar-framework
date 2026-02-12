<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Scope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeManager;
use stdClass;

#[CoversClass(ScopeManager::class)]
final class ScopeManagerTest extends TestCase
{
    #[Test]
    public function requestScopeLifecycle(): void
    {
        $manager = new ScopeManager();

        self::assertFalse($manager->isActive(Lifetime::RequestScope));

        $manager->beginScope(Lifetime::RequestScope);
        self::assertTrue($manager->isActive(Lifetime::RequestScope));

        $instance = new stdClass();
        $manager->setScopedInstance('service', Lifetime::RequestScope, $instance);
        self::assertSame($instance, $manager->getScopedInstance('service', Lifetime::RequestScope));

        $manager->endScope(Lifetime::RequestScope);
        self::assertFalse($manager->isActive(Lifetime::RequestScope));
        self::assertNull($manager->getScopedInstance('service', Lifetime::RequestScope));
    }

    #[Test]
    public function tenantScopeLifecycle(): void
    {
        $manager = new ScopeManager();

        $manager->beginScope(Lifetime::TenantScope, 'tenant-1');
        self::assertTrue($manager->isActive(Lifetime::TenantScope));
        self::assertSame('tenant-1', $manager->currentTenantId());

        $instance = new stdClass();
        $manager->setScopedInstance('service', Lifetime::TenantScope, $instance);
        self::assertSame($instance, $manager->getScopedInstance('service', Lifetime::TenantScope));

        $manager->endScope(Lifetime::TenantScope);
        self::assertFalse($manager->isActive(Lifetime::TenantScope));
        self::assertNull($manager->currentTenantId());
    }

    #[Test]
    public function tenantInstancesIsolatedByTenantId(): void
    {
        $manager = new ScopeManager();

        // Tenant 1 sets an instance
        $manager->beginScope(Lifetime::TenantScope, 'tenant-1');
        $instance1 = new stdClass();
        $instance1->name = 'tenant-1';
        $manager->setScopedInstance('service', Lifetime::TenantScope, $instance1);
        self::assertSame($instance1, $manager->getScopedInstance('service', Lifetime::TenantScope));

        // Switch to tenant 2
        $manager->beginScope(Lifetime::TenantScope, 'tenant-2');
        self::assertNull($manager->getScopedInstance('service', Lifetime::TenantScope));

        $instance2 = new stdClass();
        $instance2->name = 'tenant-2';
        $manager->setScopedInstance('service', Lifetime::TenantScope, $instance2);
        self::assertSame($instance2, $manager->getScopedInstance('service', Lifetime::TenantScope));

        // Switch back to tenant 1 — its instance is still there
        $manager->beginScope(Lifetime::TenantScope, 'tenant-1');
        self::assertSame($instance1, $manager->getScopedInstance('service', Lifetime::TenantScope));
    }

    #[Test]
    public function endTenantScopeEvictsOnlyCurrentTenant(): void
    {
        $manager = new ScopeManager();

        // Set instances for two tenants
        $manager->beginScope(Lifetime::TenantScope, 'tenant-1');
        $manager->setScopedInstance('svc', Lifetime::TenantScope, new stdClass());

        $manager->beginScope(Lifetime::TenantScope, 'tenant-2');
        $manager->setScopedInstance('svc', Lifetime::TenantScope, new stdClass());

        // End tenant-2 scope
        $manager->endScope(Lifetime::TenantScope);

        // Tenant-1 instances survive
        $manager->beginScope(Lifetime::TenantScope, 'tenant-1');
        self::assertNotNull($manager->getScopedInstance('svc', Lifetime::TenantScope));
    }

    #[Test]
    public function beginRequestScopeEvictsStaleInstances(): void
    {
        $manager = new ScopeManager();

        $manager->beginScope(Lifetime::RequestScope);
        $manager->setScopedInstance('svc', Lifetime::RequestScope, new stdClass());

        // Begin new request scope — stale instances evicted
        $manager->beginScope(Lifetime::RequestScope);
        self::assertNull($manager->getScopedInstance('svc', Lifetime::RequestScope));
    }

    #[Test]
    public function singletonAndTransientAreAlwaysActive(): void
    {
        $manager = new ScopeManager();

        self::assertTrue($manager->isActive(Lifetime::Singleton));
        self::assertTrue($manager->isActive(Lifetime::Transient));
    }
}
