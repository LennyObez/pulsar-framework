<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Tenant\TenantDeadLetterRouter;
use Pulsar\Queue\Tenant\TenantFanOutDispatcher;
use Pulsar\Queue\Tenant\TenantJobMiddleware;
use Pulsar\Queue\Tenant\TenantQueueRouter;
use Pulsar\Tenancy\Guard\TenantId;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantProviderInterface;

#[CoversClass(TenantJobMiddleware::class)]
#[CoversClass(TenantQueueRouter::class)]
#[CoversClass(TenantFanOutDispatcher::class)]
#[CoversClass(TenantDeadLetterRouter::class)]
final class TenantQueueIsolationTest extends TestCase
{
    private TenantContext $context;
    private TenantScope $scope;
    private TenantJobMiddleware $middleware;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->scope = new TenantScope($this->context);
        $this->middleware = new TenantJobMiddleware($this->scope, $this->context);
    }

    #[Test]
    public function test_tenant_a_job_cannot_read_tenant_b_queue(): void
    {
        $driver = new InMemoryDriver();
        $config = new QueueConfig(driver: QueueDriverType::Memory, defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        // Dispatch a job for tenant A via the queue router
        $this->context->set(new Tenant(id: 'tenant-a', name: 'Tenant A'));
        $manager->dispatch(jobClass: 'App\\Jobs\\TestJob', payload: '{}', queue: 'emails:tenant:tenant-a');
        $this->context->clear();

        // Tenant B's queue should be empty
        self::assertSame(0, $driver->size('emails:tenant:tenant-b'));

        // Tenant A's queue should have the job
        self::assertSame(1, $driver->size('emails:tenant:tenant-a'));
    }

    #[Test]
    public function test_middleware_stamps_correct_tenant_on_each_dispatch(): void
    {
        // Dispatch for tenant A
        $this->context->set(new Tenant(id: 'tenant-a', name: 'Tenant A'));
        $envelopeA = $this->createEnvelope(tenantId: null);

        $capturedA = null;
        $this->middleware->handle($envelopeA, static function (JobEnvelope $e) use (&$capturedA): string {
            $capturedA = $e->tenantId;

            return 'ok';
        });

        // Clear and switch to tenant B
        $this->context->clear();
        $this->context->set(new Tenant(id: 'tenant-b', name: 'Tenant B'));
        $envelopeB = $this->createEnvelope(tenantId: null);

        $capturedB = null;
        $this->middleware->handle($envelopeB, static function (JobEnvelope $e) use (&$capturedB): string {
            $capturedB = $e->tenantId;

            return 'ok';
        });

        self::assertSame('tenant-a', $capturedA);
        self::assertSame('tenant-b', $capturedB);
        self::assertNotSame($capturedA, $capturedB);
    }

    #[Test]
    public function test_fan_out_creates_independent_jobs(): void
    {
        $tenants = [new TenantId('alpha'), new TenantId('beta'), new TenantId('gamma')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        $driver = new InMemoryDriver();
        $config = new QueueConfig(driver: QueueDriverType::Memory, defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $result = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        // Each tenant should get exactly one independent job
        self::assertSame(3, $result->dispatched);
        self::assertSame(0, $result->failed);

        // All jobs are in the driver, each is independent
        $allJobs = $driver->getAll();
        self::assertCount(3, $allJobs);

        // Each job should have a unique ID
        $ids = [];
        foreach ($allJobs as $job) {
            self::assertNotContains($job->id, $ids);
            $ids[] = $job->id;
        }
    }

    #[Test]
    public function test_scope_fully_reset_between_fan_out_dispatches(): void
    {
        $tenants = [new TenantId('first'), new TenantId('second'), new TenantId('third')];

        $provider = $this->createStub(TenantProviderInterface::class);
        $provider->method('getActiveTenants')->willReturn($tenants);

        /** @var list<array{tenantId: ?string, scopeActive: bool}> $snapshots */
        $snapshots = [];

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::exactly(3))
            ->method('push')
            ->willReturnCallback(function () use (&$snapshots): string {
                $snapshots[] = [
                    'tenantId' => $this->context->isResolved()
                        ? $this->context->get()->id
                        : null,
                    'scopeActive' => $this->scope->activeTenantId !== null,
                ];

                return 'job-id';
            });

        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);
        $dispatcher = new TenantFanOutDispatcher($provider, $manager, $this->scope);

        $_ = $dispatcher->dispatch('App\\Jobs\\CleanupJob', '{}');

        // Each dispatch should have been in isolation with the correct tenant
        self::assertSame('first', $snapshots[0]['tenantId']);
        self::assertSame('second', $snapshots[1]['tenantId']);
        self::assertSame('third', $snapshots[2]['tenantId']);

        // After all fan-out, scope must be fully cleared
        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->activeTenantId);
    }

    #[Test]
    public function test_dlq_isolation_per_tenant(): void
    {
        $router = new TenantDeadLetterRouter();

        // Route DLQ for tenant A
        $envelopeA = $this->createEnvelope(tenantId: 'tenant-a', queue: 'dlq');
        $capturedQueueA = null;
        $router->handle($envelopeA, static function (JobEnvelope $e) use (&$capturedQueueA): string {
            $capturedQueueA = $e->queue;

            return 'ok';
        });

        // Route DLQ for tenant B
        $envelopeB = $this->createEnvelope(tenantId: 'tenant-b', queue: 'dlq');
        $capturedQueueB = null;
        $router->handle($envelopeB, static function (JobEnvelope $e) use (&$capturedQueueB): string {
            $capturedQueueB = $e->queue;

            return 'ok';
        });

        // Each tenant gets their own DLQ
        self::assertSame('dlq:tenant:tenant-a', $capturedQueueA);
        self::assertSame('dlq:tenant:tenant-b', $capturedQueueB);
        self::assertNotSame($capturedQueueA, $capturedQueueB);
    }

    private function createEnvelope(?string $tenantId = null, string $queue = 'default'): JobEnvelope
    {
        return new JobEnvelope(
            id: 'job-001',
            jobClass: 'App\\Jobs\\Test',
            payload: '{}',
            queue: $queue,
            idempotencyKey: '',
            correlationId: 'corr-001',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Fixed,
            retryDelayMs: 0,
            tenantId: $tenantId,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );
    }
}
