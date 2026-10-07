<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\PropagateContext;

#[CoversClass(PropagateContext::class)]
final class PropagateContextTest extends TestCase
{
    private function createEnvelope(
        string $correlationId = '',
        ?string $subjectId = null,
        ?string $tenantId = null,
    ): JobEnvelope {
        return new JobEnvelope(
            id: 'job-ctx-001',
            jobClass: 'App\\Jobs\\SyncData',
            payload: '{}',
            queue: 'default',
            idempotencyKey: 'idem-001',
            correlationId: $correlationId,
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Fixed,
            retryDelayMs: 1000,
            tenantId: $tenantId,
            subjectId: $subjectId,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1709827200,
            encrypted: false,
        );
    }

    #[Test]
    public function injectsContextWhenAvailable(): void
    {
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: CorrelationId::fromString('aabbccddaabbccddaabbccddaabbccdd'),
            causationId: CausationId::fromString('11223344112233441122334411223344'),
            actor: 'user-99',
            tenantId: 'tenant-abc',
        ));

        $middleware = new PropagateContext($holder);
        $envelope = $this->createEnvelope();

        $result = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$result): string {
            $result = $e;

            return 'done';
        });

        self::assertInstanceOf(JobEnvelope::class, $result);
        self::assertSame('aabbccddaabbccddaabbccddaabbccdd', $result->correlationId);
        self::assertSame('user-99', $result->subjectId);
        self::assertSame('tenant-abc', $result->tenantId);
    }

    #[Test]
    public function preservesExistingEnvelopeValuesWhenSet(): void
    {
        $holder = new RequestContextHolder();
        $holder->set(new RequestContext(
            correlationId: CorrelationId::fromString('aabbccddaabbccddaabbccddaabbccdd'),
            causationId: CausationId::fromString('11223344112233441122334411223344'),
            actor: 'overridden-user',
            tenantId: 'overridden-tenant',
        ));

        $middleware = new PropagateContext($holder);
        $envelope = $this->createEnvelope(
            correlationId: 'existing-corr-id',
            subjectId: 'original-user',
            tenantId: 'original-tenant',
        );

        $result = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$result): string {
            $result = $e;

            return 'done';
        });

        self::assertInstanceOf(JobEnvelope::class, $result);
        self::assertSame('existing-corr-id', $result->correlationId);
        self::assertSame('original-user', $result->subjectId);
        self::assertSame('original-tenant', $result->tenantId);
    }

    #[Test]
    public function passesEnvelopeThroughWhenNoContext(): void
    {
        $holder = new RequestContextHolder();

        $middleware = new PropagateContext($holder);
        $envelope = $this->createEnvelope();

        $result = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$result): string {
            $result = $e;

            return 'done';
        });

        self::assertSame($envelope, $result);
    }
}
