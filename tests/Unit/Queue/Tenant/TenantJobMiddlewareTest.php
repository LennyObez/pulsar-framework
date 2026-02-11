<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Tenant\TenantJobMiddleware;
use Pulsar\Tenancy\Guard\TenantScope;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use RuntimeException;

#[CoversClass(TenantJobMiddleware::class)]
final class TenantJobMiddlewareTest extends TestCase
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
    public function stampsTenantIdOnDispatchWhenContextResolved(): void
    {
        $this->context->set(new Tenant(id: 'acme', name: 'Acme'));
        $envelope = $this->createEnvelope(tenantId: null);

        $captured = null;
        $this->middleware->handle($envelope, static function (JobEnvelope $e) use (&$captured): string {
            $captured = $e->tenantId;

            return 'ok';
        });

        self::assertSame('acme', $captured);
    }

    #[Test]
    public function passesThroughWhenNoContext(): void
    {
        $envelope = $this->createEnvelope(tenantId: null);

        $capturedTenantId = 'should-be-null';
        $this->middleware->handle($envelope, static function (JobEnvelope $e) use (&$capturedTenantId): string {
            $capturedTenantId = $e->tenantId;

            return 'ok';
        });

        self::assertNull($capturedTenantId);
        self::assertFalse($this->context->isResolved());
    }

    #[Test]
    public function restoresTenantContextOnExecution(): void
    {
        $envelope = $this->createEnvelope(tenantId: 'acme');

        $resolvedDuringJob = false;
        $this->middleware->handle($envelope, function (JobEnvelope $e) use (&$resolvedDuringJob): string {
            $resolvedDuringJob = $this->context->isResolved();

            return 'ok';
        });

        self::assertTrue($resolvedDuringJob);
    }

    #[Test]
    public function cleansUpContextOnSuccess(): void
    {
        $envelope = $this->createEnvelope(tenantId: 'acme');

        $this->middleware->handle($envelope, static fn(JobEnvelope $e): string => 'ok');

        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());
    }

    #[Test]
    public function cleansUpContextOnFailure(): void
    {
        $envelope = $this->createEnvelope(tenantId: 'acme');

        try {
            $this->middleware->handle($envelope, static function (JobEnvelope $e): never {
                throw new RuntimeException('job failed');
            });
        } catch (RuntimeException) {
            // Expected
        }

        self::assertFalse($this->context->isResolved());
        self::assertNull($this->scope->getActiveTenantId());
    }

    #[Test]
    public function preservesExistingTenantIdOnEnvelope(): void
    {
        $envelope = $this->createEnvelope(tenantId: 'existing');

        $captured = null;
        $this->middleware->handle($envelope, static function (JobEnvelope $e) use (&$captured): string {
            $captured = $e->tenantId;

            return 'ok';
        });

        self::assertSame('existing', $captured);
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
