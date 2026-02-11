<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Guard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Tenancy\Exception\TenantContextMismatchException;
use Pulsar\Tenancy\Exception\TenantContextMissingException;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantIsolationGuard;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;

#[CoversClass(TenantIsolationGuard::class)]
final class TenantIsolationGuardTest extends TestCase
{
    #[Test]
    public function test_assert_context_passes_when_resolved(): void
    {
        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme'));
        $guard = new TenantIsolationGuard($context);

        $guard->assertContext();

        self::assertTrue($context->isResolved());
    }

    #[Test]
    public function test_assert_context_throws_when_not_resolved(): void
    {
        $context = new TenantContext();
        $guard = new TenantIsolationGuard($context);

        $this->expectException(TenantContextMissingException::class);

        $guard->assertContext();
    }

    #[Test]
    public function test_assert_context_matches_passes_when_matching(): void
    {
        $context = new TenantContext();
        $context->set(new Tenant(id: 'acme', name: 'Acme'));
        $guard = new TenantIsolationGuard($context);

        $guard->assertContextMatches(new TenantId('acme'));

        self::assertTrue($context->isResolved());
    }

    #[Test]
    public function test_assert_context_matches_throws_when_not_resolved(): void
    {
        $context = new TenantContext();
        $guard = new TenantIsolationGuard($context);

        $this->expectException(TenantContextMissingException::class);

        $guard->assertContextMatches(new TenantId('acme'));
    }

    #[Test]
    public function test_assert_context_matches_throws_on_mismatch(): void
    {
        $context = new TenantContext();
        $context->set(new Tenant(id: 'other', name: 'Other'));
        $guard = new TenantIsolationGuard($context);

        $this->expectException(TenantContextMismatchException::class);

        $guard->assertContextMatches(new TenantId('acme'));
    }

    #[Test]
    public function test_mismatch_logs_security_event(): void
    {
        $context = new TenantContext();
        $context->set(new Tenant(id: 'other', name: 'Other'));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                null,
                'tenant_context_mismatch',
                'acme',
                self::callback(static fn(array $metadata): bool => $metadata['expected'] === 'acme' && $metadata['actual'] === 'other'),
            );

        $guard = new TenantIsolationGuard($context, $auditLogger);

        try {
            $guard->assertContextMatches(new TenantId('acme'));
            self::fail('Expected TenantContextMismatchException');
        } catch (TenantContextMismatchException) {
            // Expected — the mock assertion verifies the audit log call.
        }
    }
}
