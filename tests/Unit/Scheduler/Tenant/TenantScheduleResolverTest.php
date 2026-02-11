<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\CallbackJob;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Tenant\MaintenanceWindow;
use Pulsar\Scheduler\Tenant\TenantScheduleProviderInterface;
use Pulsar\Scheduler\Tenant\TenantScheduleResolver;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\TenantProviderInterface;

final class TenantScheduleResolverTest extends TestCase
{
    public function test_resolve_returns_jobs_for_tenant(): void
    {
        $tenantId = new TenantId('tenant-a');
        $job = new CallbackJob('test-job', Schedule::daily(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturn([$job]);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $result = $resolver->resolve($tenantId);

        self::assertCount(1, $result);
        self::assertSame('test-job', $result[0]->getName());
    }

    public function test_resolve_all_returns_all_tenants_jobs(): void
    {
        $tenantA = new TenantId('tenant-a');
        $tenantB = new TenantId('tenant-b');
        $jobA = new CallbackJob('job-a', Schedule::daily(), static fn(JobContext $ctx): string => 'ok');
        $jobB = new CallbackJob('job-b', Schedule::hourly(), static fn(JobContext $ctx): string => 'ok');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getScheduledJobs')->willReturnCallback(
            static fn(TenantId $id): array => match ($id->toString()) {
                'tenant-a' => [$jobA],
                'tenant-b' => [$jobB],
                default => [],
            },
        );

        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([$tenantA, $tenantB]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $result = $resolver->resolveAll();

        self::assertCount(2, $result);
        self::assertArrayHasKey('tenant-a', $result);
        self::assertArrayHasKey('tenant-b', $result);
        self::assertSame('job-a', $result['tenant-a'][0]->getName());
        self::assertSame('job-b', $result['tenant-b'][0]->getName());
    }

    public function test_resolve_all_with_empty_tenant_list(): void
    {
        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $tenantProvider = $this->createStub(TenantProviderInterface::class);
        $tenantProvider->method('getActiveTenants')->willReturn([]);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);
        $result = $resolver->resolveAll();

        self::assertSame([], $result);
    }

    public function test_is_in_maintenance_window_true(): void
    {
        $tenantId = new TenantId('tenant-a');
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Upgrade',
        );

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getMaintenanceWindow')->willReturn($window);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);

        self::assertTrue($resolver->isInMaintenanceWindow($tenantId, new DateTimeImmutable('2026-03-01 03:00:00')));
    }

    public function test_is_in_maintenance_window_false_no_window(): void
    {
        $tenantId = new TenantId('tenant-a');

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getMaintenanceWindow')->willReturn(null);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);

        self::assertFalse($resolver->isInMaintenanceWindow($tenantId, new DateTimeImmutable('2026-03-01 03:00:00')));
    }

    public function test_is_in_maintenance_window_false_outside_window(): void
    {
        $tenantId = new TenantId('tenant-a');
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Upgrade',
        );

        $provider = $this->createStub(TenantScheduleProviderInterface::class);
        $provider->method('getMaintenanceWindow')->willReturn($window);

        $tenantProvider = $this->createStub(TenantProviderInterface::class);

        $resolver = new TenantScheduleResolver($provider, $tenantProvider);

        self::assertFalse($resolver->isInMaintenanceWindow($tenantId, new DateTimeImmutable('2026-03-01 05:00:00')));
    }
}
