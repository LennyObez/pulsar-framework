<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\TenantContext;

#[CoversClass(TenantScope::class)]
final class TenantScopeTest extends TestCase
{
    #[Test]
    public function test_enter_sets_tenant_context(): void
    {
        $context = new TenantContext();
        $scope = new TenantScope($context);

        $scope->enter(new TenantId('acme'));

        self::assertTrue($context->isResolved());
        self::assertSame('acme', $context->get()->id);
    }

    #[Test]
    public function test_exit_clears_tenant_context(): void
    {
        $context = new TenantContext();
        $scope = new TenantScope($context);

        $scope->enter(new TenantId('acme'));
        $scope->exit();

        self::assertFalse($context->isResolved());
        self::assertNull($scope->getActiveTenantId());
    }

    #[Test]
    public function test_reset_fully_clears_state(): void
    {
        $context = new TenantContext();
        $scope = new TenantScope($context);

        $scope->enter(new TenantId('acme'));
        $scope->reset();

        self::assertFalse($context->isResolved());
        self::assertNull($scope->getActiveTenantId());
    }

    #[Test]
    public function test_get_active_tenant_id(): void
    {
        $context = new TenantContext();
        $scope = new TenantScope($context);

        self::assertNull($scope->getActiveTenantId());

        $tenantId = new TenantId('acme');
        $scope->enter($tenantId);

        $activeTenantId = $scope->getActiveTenantId();
        self::assertNotNull($activeTenantId);
        self::assertTrue($tenantId->equals($activeTenantId));
    }
}
