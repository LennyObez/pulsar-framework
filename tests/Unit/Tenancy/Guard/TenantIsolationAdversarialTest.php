<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\Exception\TenancyException;
use Pulsar\Tenancy\Exception\TenantContextMismatchException;
use Pulsar\Tenancy\Exception\TenantContextMissingException;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantIsolationGuard;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use ReflectionClass;
use RuntimeException;

#[CoversClass(TenantIsolationGuard::class)]
#[CoversClass(TenantScope::class)]
#[CoversClass(TenantContext::class)]
final class TenantIsolationAdversarialTest extends TestCase
{
    private TenantContext $context;
    private TenantIsolationGuard $guard;
    private TenantScope $scope;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->guard = new TenantIsolationGuard($this->context);
        $this->scope = new TenantScope($this->context);
    }

    #[Test]
    public function test_guard_rejects_access_without_any_tenant_context(): void
    {
        $this->expectException(TenantContextMissingException::class);

        $this->guard->assertContext();
    }

    #[Test]
    public function test_guard_rejects_cross_tenant_access(): void
    {
        $this->scope->enter(new TenantId('tenant-a'));

        $this->expectException(TenantContextMismatchException::class);

        $this->guard->assertContextMatches(new TenantId('tenant-b'));
    }

    #[Test]
    public function test_scope_reset_clears_all_state(): void
    {
        $this->scope->enter(new TenantId('tenant-a'));

        self::assertTrue($this->context->isResolved());
        self::assertSame('tenant-a', $this->context->get()->id);
        self::assertNotNull($this->scope->getActiveTenantId());

        $this->scope->reset();

        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());
        self::assertNull($this->context->tryGet());
    }

    #[Test]
    public function test_scope_cannot_access_previous_tenant_after_reset(): void
    {
        $this->scope->enter(new TenantId('tenant-a'));
        self::assertSame('tenant-a', $this->context->get()->id);

        $this->scope->reset();

        $this->scope->enter(new TenantId('tenant-b'));
        self::assertSame('tenant-b', $this->context->get()->id);

        // Verify tenant A is completely inaccessible
        $this->expectException(TenantContextMismatchException::class);
        $this->guard->assertContextMatches(new TenantId('tenant-a'));
    }

    #[Test]
    public function test_consecutive_tenant_switches_are_isolated(): void
    {
        // Enter tenant A
        $this->scope->enter(new TenantId('tenant-a'));
        self::assertSame('tenant-a', $this->context->get()->id);
        $activeA = $this->scope->getActiveTenantId();
        self::assertNotNull($activeA);
        self::assertTrue(new TenantId('tenant-a')->equals($activeA));

        // Exit tenant A
        $this->scope->exit();
        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());

        // Enter tenant B
        $this->scope->enter(new TenantId('tenant-b'));
        self::assertSame('tenant-b', $this->context->get()->id);
        $activeB = $this->scope->getActiveTenantId();
        self::assertNotNull($activeB);
        self::assertTrue(new TenantId('tenant-b')->equals($activeB));

        // Verify only B is active, A is not accessible
        $this->guard->assertContextMatches(new TenantId('tenant-b'));

        $this->expectException(TenantContextMismatchException::class);
        $this->guard->assertContextMatches(new TenantId('tenant-a'));
    }

    #[Test]
    public function test_exception_during_scoped_work_still_cleans_up(): void
    {
        $this->scope->enter(new TenantId('tenant-a'));

        try {
            // Simulate an exception during scoped work
            throw new RuntimeException('Simulated failure inside tenant scope');
        } catch (RuntimeException) {
            // Application-level exception caught — scope must still be clearable
        }

        // The scope can be manually cleaned up after an exception
        $this->scope->exit();
        $this->scope->reset();

        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());
    }

    #[Test]
    public function test_guard_cannot_be_bypassed_by_direct_context_mutation(): void
    {
        // Directly set tenant A on the context (bypassing scope)
        $this->context->set(new Tenant(id: 'tenant-a', name: 'Tenant A'));

        // Guard still enforces correctly — it reads from context
        $this->guard->assertContext();
        $this->guard->assertContextMatches(new TenantId('tenant-a'));

        // Clear and verify guard re-reads state (not cached)
        $this->context->clear();

        $this->expectException(TenantContextMissingException::class);
        $this->guard->assertContext();
    }

    #[Test]
    public function test_guard_detects_context_swap_between_checks(): void
    {
        // Start with tenant A
        $this->context->set(new Tenant(id: 'tenant-a', name: 'Tenant A'));
        $this->guard->assertContextMatches(new TenantId('tenant-a'));

        // Silently swap to tenant B (simulating a bug or attack)
        $this->context->clear();
        $this->context->set(new Tenant(id: 'tenant-b', name: 'Tenant B'));

        // Guard detects the swap — tenant A check now fails
        $this->expectException(TenantContextMismatchException::class);
        $this->guard->assertContextMatches(new TenantId('tenant-a'));
    }

    #[Test]
    public function test_multiple_guards_on_same_context_all_enforce(): void
    {
        $guardA = new TenantIsolationGuard($this->context);
        $guardB = new TenantIsolationGuard($this->context);

        // No context set — both guards reject
        $missingCount = 0;

        try {
            $guardA->assertContext();
        } catch (TenantContextMissingException) {
            ++$missingCount;
        }

        try {
            $guardB->assertContext();
        } catch (TenantContextMissingException) {
            ++$missingCount;
        }

        self::assertSame(2, $missingCount);

        // Set context — both guards accept the same tenant
        $this->context->set(new Tenant(id: 'tenant-x', name: 'Tenant X'));
        $guardA->assertContextMatches(new TenantId('tenant-x'));
        $guardB->assertContextMatches(new TenantId('tenant-x'));

        // Both guards reject a different tenant
        $mismatchCount = 0;

        try {
            $guardA->assertContextMatches(new TenantId('tenant-y'));
        } catch (TenantContextMismatchException) {
            ++$mismatchCount;
        }

        try {
            $guardB->assertContextMatches(new TenantId('tenant-y'));
        } catch (TenantContextMismatchException) {
            ++$mismatchCount;
        }

        self::assertSame(2, $mismatchCount);
    }

    #[Test]
    public function test_guard_is_final_and_cannot_be_subclassed(): void
    {
        $reflection = new ReflectionClass(TenantIsolationGuard::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function test_context_get_throws_without_resolved_tenant(): void
    {
        // Accessing context.get() without a resolved tenant must throw
        $this->expectException(TenancyException::class);

        (void) $this->context->get();
    }

    #[Test]
    public function test_request_state_reset_clears_tenant(): void
    {
        $this->context->set(new Tenant(id: 'tenant-a', name: 'Tenant A'));
        self::assertTrue($this->context->isResolved());

        // resetRequestState is called between HTTP requests
        $this->context->resetRequestState();

        self::assertFalse($this->context->isResolved());
        self::assertNull($this->context->tryGet());
    }
}
