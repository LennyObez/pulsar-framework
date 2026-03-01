<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\Driver\InMemoryDriver;
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

final class TenantFanOutScheduleTest extends TestCase
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

    public function test_tick_dispatches_due_jobs_per_tenant(): void
    {
        $now = new DateTimeImmutable('2026-03-01 00:00:00');
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');

        // everyMinute is always due
        $job = new CallbackJob('every-min', Schedule::everyMinute(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);
        $provider->method('getMaintenanceWindow')->willReturn(null);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        $result = $schedule->tick($now);

        self::assertSame(2, $result->tenantsProcessed);
        self::assertSame(2, $result->jobsDispatched);
        self::assertSame(0, $result->tenantsSkipped);
        self::assertSame(0, $result->jobsFailed);
        self::assertSame(2, $this->driver->size('default'));
    }

    public function test_tick_skips_tenants_in_maintenance_window(): void
    {
        $now = new DateTimeImmutable('2026-03-01 03:00:00');
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');

        $job = new CallbackJob('every-min', Schedule::everyMinute(), static fn(JobContext $ctx): string => 'ok');

        $maintenanceWindow = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Upgrade',
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
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        $result = $schedule->tick($now);

        self::assertSame(1, $result->tenantsProcessed);
        self::assertSame(1, $result->jobsDispatched);
        self::assertSame(1, $result->tenantsSkipped);
        self::assertSame(0, $result->jobsFailed);
    }

    public function test_tick_handles_empty_tenant_list(): void
    {
        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([]);

        $provider = $this->createStub(TenantScheduleProviderInterface::class);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        $result = $schedule->tick(new DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertSame(0, $result->tenantsProcessed);
        self::assertSame(0, $result->jobsDispatched);
        self::assertSame(0, $result->tenantsSkipped);
        self::assertSame(0, $result->jobsFailed);
    }

    public function test_tick_skips_non_due_jobs(): void
    {
        // Use a schedule that fires at midnight only, and run at 13:00
        $now = new DateTimeImmutable('2026-03-01 13:00:00');
        $tenantA = new TenantId('tenant-a');

        $job = new CallbackJob('midnight-only', Schedule::daily(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);
        $provider->method('getMaintenanceWindow')->willReturn(null);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $schedule = new TenantFanOutSchedule($resolver, $tenantProvider, $this->queueManager, $this->scope);

        $result = $schedule->tick($now);

        self::assertSame(1, $result->tenantsProcessed);
        self::assertSame(0, $result->jobsDispatched);
        self::assertSame(0, $result->tenantsSkipped);
    }

    public function test_tick_cleans_up_scope_between_tenants(): void
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

        // After tick, tenant scope should be fully cleared
        self::assertNull($this->scope->activeTenantId);
        self::assertFalse($this->tenantContext->isResolved());
    }
}
