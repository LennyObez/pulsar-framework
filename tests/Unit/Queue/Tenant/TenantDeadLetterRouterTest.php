<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Tenant\TenantDeadLetterRouter;

#[CoversClass(TenantDeadLetterRouter::class)]
final class TenantDeadLetterRouterTest extends TestCase
{
    #[Test]
    public function routesToTenantSpecificDlq(): void
    {
        $router = new TenantDeadLetterRouter();
        $envelope = $this->createEnvelope(queue: 'dlq', tenantId: 'acme');

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('dlq:tenant:acme', $capturedQueue);
    }

    #[Test]
    public function passesNonDlqQueueThrough(): void
    {
        $router = new TenantDeadLetterRouter();
        $envelope = $this->createEnvelope(queue: 'emails', tenantId: 'acme');

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('emails', $capturedQueue);
    }

    #[Test]
    public function passesThroughWhenNoTenantId(): void
    {
        $router = new TenantDeadLetterRouter();
        $envelope = $this->createEnvelope(queue: 'dlq', tenantId: null);

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('dlq', $capturedQueue);
    }

    #[Test]
    public function routesDlqPrefixedQueues(): void
    {
        $router = new TenantDeadLetterRouter();
        $envelope = $this->createEnvelope(queue: 'dlq:emails', tenantId: 'acme');

        $capturedQueue = null;
        $router->handle($envelope, static function (JobEnvelope $e) use (&$capturedQueue): string {
            $capturedQueue = $e->queue;

            return 'ok';
        });

        self::assertSame('dlq:tenant:acme', $capturedQueue);
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
