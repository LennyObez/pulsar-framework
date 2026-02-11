<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\QueueConfig;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Tenant\TenantFanOutDispatcher;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantProviderInterface;
use RuntimeException;

#[CoversClass(TenantFanOutDispatcher::class)]
final class TenantFanOutDispatcherTest extends TestCase
{
    private TenantContext $context;
    private TenantScope $scope;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->scope = new TenantScope($this->context);
    }

    #[Test]
    public function dispatchesJobPerTenant(): void
    {
        $tenants = [new TenantId('acme'), new TenantId('globex'), new TenantId('initech')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::exactly(3))
            ->method('push')
            ->willReturn('job-id');

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $result = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        self::assertSame(3, $result->dispatched);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->failedTenantIds);
    }

    #[Test]
    public function entersTenantScopePerDispatch(): void
    {
        $tenants = [new TenantId('acme'), new TenantId('globex')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        /** @var list<string|null> $capturedTenantIds */
        $capturedTenantIds = [];

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(function () use (&$capturedTenantIds): string {
                $capturedTenantIds[] = $this->context->isResolved()
                    ? $this->context->get()->id
                    : null;

                return 'job-id';
            });

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $_ = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        self::assertSame(['acme', 'globex'], $capturedTenantIds);
        // Context should be cleared after fan-out completes
        self::assertFalse($this->context->isResolved());
    }

    #[Test]
    public function singleTenantFailureDoesNotAffectOthers(): void
    {
        $tenants = [new TenantId('acme'), new TenantId('bad-tenant'), new TenantId('initech')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $callCount = 0;
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::exactly(3))
            ->method('push')
            ->willReturnCallback(static function () use (&$callCount): string {
                ++$callCount;
                if ($callCount === 2) {
                    throw new RuntimeException('Dispatch failed for bad-tenant');
                }

                return 'job-id';
            });

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $result = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        self::assertSame(2, $result->dispatched);
        self::assertSame(1, $result->failed);
        self::assertSame(['bad-tenant'], $result->failedTenantIds);
    }

    #[Test]
    public function returnsFanOutResultWithCounts(): void
    {
        $tenants = [new TenantId('acme'), new TenantId('globex')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')->willReturn('job-id');

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $result = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        self::assertSame(2, $result->dispatched);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->failedTenantIds);
    }

    #[Test]
    public function logsFanOutEvents(): void
    {
        $tenants = [new TenantId('acme')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')->willReturn('job-id');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        // Expect: fan_out_started + fan_out_completed (at minimum)
        $auditLogger->expects(self::atLeast(2))
            ->method('log');

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope, $auditLogger);

        $_ = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');
    }

    #[Test]
    public function emptyTenantListDispatchesNothing(): void
    {
        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn([]);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::never())->method('push');

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $result = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        self::assertSame(0, $result->dispatched);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->failedTenantIds);
    }

    #[Test]
    public function cleansUpScopeEvenOnDispatchFailure(): void
    {
        $tenants = [new TenantId('acme')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')
            ->willThrowException(new RuntimeException('driver failure'));

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $_ = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        // Scope must be fully cleaned up despite failure
        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());
    }
}
