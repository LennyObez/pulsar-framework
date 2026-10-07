<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Tenant\TenantRateLimiter;
use RuntimeException;

#[CoversClass(TenantRateLimiter::class)]
final class TenantRateLimiterTest extends TestCase
{
    #[Test]
    public function acquiresPerTenantLockAndPassesThrough(): void
    {
        $handle = new LockHandle('queue:rate:acme:emails', 'token-1', microtime(true), 1);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:rate:acme:emails', 1, 0)
            ->willReturn($handle);
        $lock->expects(self::once())
            ->method('release')
            ->with($handle);

        $limiter = new TenantRateLimiter($lock);
        $envelope = $this->createEnvelope(queue: 'emails', tenantId: 'acme');

        $result = $limiter->handle($envelope, static fn(JobEnvelope $e): string => 'processed');

        self::assertSame('processed', $result);
    }

    #[Test]
    public function throwsWhenRateLimitExceeded(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('acquire')
            ->willThrowException(new LockAcquisitionException('rate limit'));

        $limiter = new TenantRateLimiter($lock);
        $envelope = $this->createEnvelope(queue: 'high-volume', tenantId: 'acme');

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('Rate limit exceeded');

        $limiter->handle($envelope, static fn(JobEnvelope $e): string => 'never');
    }

    #[Test]
    public function releasesLockEvenWhenNextThrows(): void
    {
        $handle = new LockHandle('queue:rate:acme:default', 'token-1', microtime(true), 1);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($handle);
        $lock->expects(self::once())->method('release')->with($handle);

        $limiter = new TenantRateLimiter($lock);
        $envelope = $this->createEnvelope(tenantId: 'acme');

        try {
            $limiter->handle($envelope, static function (JobEnvelope $e): never {
                throw new RuntimeException('job failed');
            });
        } catch (RuntimeException) {
            // Expected
        }
    }

    #[Test]
    public function passesCustomTtlAndTimeoutToLock(): void
    {
        $handle = new LockHandle('queue:rate:acme:default', 'token-1', microtime(true), 5);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:rate:acme:default', 5, 2000)
            ->willReturn($handle);
        $lock->method('release')->willReturn(true);

        $limiter = new TenantRateLimiter($lock, ttlSeconds: 5, timeoutMs: 2000);
        $envelope = $this->createEnvelope(tenantId: 'acme');

        $limiter->handle($envelope, static fn(JobEnvelope $e): string => 'ok');
    }

    #[Test]
    public function skipsLockWhenNoTenantId(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::never())->method('acquire');
        $lock->expects(self::never())->method('release');

        $limiter = new TenantRateLimiter($lock);
        $envelope = $this->createEnvelope(tenantId: null);

        $result = $limiter->handle($envelope, static fn(JobEnvelope $e): string => 'passed');

        self::assertSame('passed', $result);
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
