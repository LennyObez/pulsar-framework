<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Tenant\MaintenanceWindow;
use Pulsar\Scheduler\Tenant\TenantFanOutSchedule;
use Pulsar\Scheduler\Tenant\TenantScheduleProviderInterface;
use Pulsar\Scheduler\Tenant\TenantScheduleResolver;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantProviderInterface;
use RuntimeException;

#[CoversClass(TenantFanOutSchedule::class)]
#[CoversClass(TenantScheduleResolver::class)]
final class TenantSchedulerIsolationTest extends TestCase
{
    private InMemoryDriver $driver;
    private QueueManager $queueManager;
    private TenantContext $tenantContext;
    private TenantScope $scope;

    protected function setUp(): void
    {
        $this->driver = new InMemoryDriver();
        $config = new QueueConfig(driver: QueueDriverType::Memory, defaultQueue: 'default');
        $this->queueManager = new QueueManager($config, $this->driver);
        $this->tenantContext = new TenantContext();
        $this->scope = new TenantScope($this->tenantContext);
    }

    #[Test]
    public function test_tick_does_not_leak_tenant_context_between_tenants(): void
    {
        $now = new DateTimeImmutable('2026-03-01 00:00:00');
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');

        $job = new CallbackJob('every-min', Schedule::everyMinute(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);
        $provider->method('getMaintenanceWindow')->willReturn(null);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        (void) $schedule->tick($now);

        // After the tick completes, the scope must be fully cleared
        self::assertNull($this->scope->activeTenantId);
        self::assertFalse($this->tenantContext->isResolved());
        self::assertNull($this->tenantContext->tryGet());
    }

    #[Test]
    public function test_maintenance_window_only_affects_target_tenant(): void
    {
        $now = new DateTimeImmutable('2026-03-01 03:00:00');
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');
        $tenantC = new TenantId('tenant-c');

        $job = new CallbackJob('every-min', Schedule::everyMinute(), static fn(JobContext $ctx): string => 'ok');

        $maintenanceWindow = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Database migration',
        );

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);
        $provider->method('getMaintenanceWindow')->willReturnCallback(
            static fn(TenantId $id): ?MaintenanceWindow => match ($id->toString()) {
                'tenant-a' => $maintenanceWindow,
                default => null,
            },
        );

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB, $tenantC]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        $result = $schedule->tick($now);

        // Tenant A is in maintenance — skipped
        self::assertSame(1, $result->tenantsSkipped);
        // Tenants B and C should be processed normally
        self::assertSame(2, $result->tenantsProcessed);
        self::assertSame(2, $result->jobsDispatched);
        // Only 2 jobs should be in the queue (tenant B and C)
        self::assertSame(2, $this->driver->size('default'));
    }

    #[Test]
    public function test_failed_tenant_dispatch_does_not_block_other_tenants(): void
    {
        $now = new DateTimeImmutable('2026-03-01 00:00:00');
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');
        $tenantC = new TenantId('tenant-c');

        $job = new CallbackJob('every-min', Schedule::everyMinute(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);
        $provider->method('getMaintenanceWindow')->willReturn(null);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB, $tenantC]);

        // Use a driver that fails for the second tenant
        $callCount = 0;
        $failingDriver = $this->createMock(QueueDriverInterface::class);
        $failingDriver->expects(self::exactly(3))
            ->method('push')
            ->willReturnCallback(static function () use (&$callCount): string {
                ++$callCount;
                if ($callCount === 2) {
                    throw new RuntimeException('Driver failure for tenant-b');
                }

                return 'job-id';
            });

        $failingManager = new QueueManager(
            new QueueConfig(driver: QueueDriverType::Memory, defaultQueue: 'default'),
            $failingDriver,
        );

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $failingManager, $this->scope);

        $result = $schedule->tick($now);

        // All three tenants should be processed (none skipped)
        self::assertSame(3, $result->tenantsProcessed);
        // Tenant B failed, but A and C succeeded
        self::assertSame(2, $result->jobsDispatched);
        self::assertSame(1, $result->jobsFailed);

        // Context must be clean after the tick despite the failure
        self::assertNull($this->scope->activeTenantId);
        self::assertFalse($this->tenantContext->isResolved());
    }
}
