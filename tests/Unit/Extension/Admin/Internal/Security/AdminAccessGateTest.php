<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Exception\AdminAccessDeniedException;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;

#[CoversClass(AdminAccessGate::class)]
final class AdminAccessGateTest extends TestCase
{
    #[Test]
    public function assertCanAccessAllowsWhenPolicyReturnsTrue(): void
    {
        /** @var PolicyInterface&MockObject $policy */
        $policy = $this->createMock(PolicyInterface::class);
        $policy->expects(self::once())->method('evaluate')->willReturn(true);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);

        // Should not throw — policy was consulted exactly once
        $gate->assertCanAccess($identity);
    }

    #[Test]
    public function assertCanAccessThrowsWhenPolicyReturnsFalse(): void
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturn(false);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);

        $this->expectException(AdminAccessDeniedException::class);
        $this->expectExceptionMessage('admin');

        $gate->assertCanAccess($identity);
    }

    #[Test]
    public function assertCanAccessThrowsWhenPolicyReturnsNull(): void
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturn(null);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);

        $this->expectException(AdminAccessDeniedException::class);

        $gate->assertCanAccess($identity);
    }

    #[Test]
    public function assertCanPerformAllowsWhenPolicyReturnsTrue(): void
    {
        /** @var PolicyInterface&MockObject $policy */
        $policy = $this->createMock(PolicyInterface::class);
        $policy->expects(self::once())->method('evaluate')->willReturn(true);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);

        // Should not throw — policy was consulted exactly once
        $gate->assertCanPerform($identity, 'users', ResourceOperation::List);
    }

    #[Test]
    public function assertCanPerformThrowsWhenPolicyReturnsFalse(): void
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturn(false);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);

        $this->expectException(AdminAccessDeniedException::class);
        $this->expectExceptionMessage('users');

        $gate->assertCanPerform($identity, 'users', ResourceOperation::Delete);
    }

    #[Test]
    public function assertCanPerformPassesCorrectPermissionForExport(): void
    {
        /** @var PolicyInterface&MockObject $policy */
        $policy = $this->createMock(PolicyInterface::class);
        $policy->expects(self::once())
            ->method('evaluate')
            ->with(
                self::anything(),
                self::callback(static fn(PolicyContext $ctx): bool => $ctx->permission === 'admin.export'),
            )
            ->willReturn(true);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);
        $gate->assertCanPerform($identity, 'orders', ResourceOperation::Export);
    }

    #[Test]
    public function assertCanPerformPassesResourceNameInContext(): void
    {
        /** @var PolicyInterface&MockObject $policy */
        $policy = $this->createMock(PolicyInterface::class);
        $policy->expects(self::once())
            ->method('evaluate')
            ->with(
                self::anything(),
                self::callback(static fn(PolicyContext $ctx): bool => $ctx->resource === 'users'),
            )
            ->willReturn(true);
        $identity = $this->createStub(IdentityInterface::class);

        $gate = new AdminAccessGate($policy);
        $gate->assertCanPerform($identity, 'users', ResourceOperation::View);
    }
}
