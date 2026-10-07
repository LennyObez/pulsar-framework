<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Tenant\TenantQueueRouter;

#[CoversClass(TenantQueueRouter::class)]
final class TenantQueueRouterTest extends TestCase
{
    #[Test]
    public function routesToTenantSpecificQueue(): void
    {
        $router = new TenantQueueRouter();
        $envelope = $this->createEnvelope(queue: 'emails', tenantId: 'acme');

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('emails:tenant:acme', $capturedQueue);
    }

    #[Test]
    public function passesOriginalQueueWhenNoTenantId(): void
    {
        $router = new TenantQueueRouter();
        $envelope = $this->createEnvelope(queue: 'emails', tenantId: null);

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('emails', $capturedQueue);
    }

    #[Test]
    public function passesOriginalQueueWhenDisabled(): void
    {
        $router = new TenantQueueRouter(enabled: false);
        $envelope = $this->createEnvelope(queue: 'emails', tenantId: 'acme');

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('emails', $capturedQueue);
    }

    #[Test]
    public function returnsNextResult(): void
    {
        $router = new TenantQueueRouter();
        $envelope = $this->createEnvelope(tenantId: 'acme');

        $result = $router->handle($envelope, static fn(JobEnvelope $e): string => 'processed');

        self::assertSame('processed', $result);
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
