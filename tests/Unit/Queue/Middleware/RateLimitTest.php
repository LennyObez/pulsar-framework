<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\RateLimit;
use RuntimeException;

#[CoversClass(RateLimit::class)]
final class RateLimitTest extends TestCase
{
    #[Test]
    public function acquiresLockByQueueNameAndPassesThrough(): void
    {
        $handle = new LockHandle('queue:rate:emails', 'token-1', microtime(true), 1);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:rate:emails', 1, 0)
            ->willReturn($handle);
        $lock->expects(self::once())
            ->method('release')
            ->with($handle);

        $middleware = new RateLimit($lock);
        $envelope = $this->createEnvelope(queue: 'emails');

        $result = $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'processed');

        self::assertSame('processed', $result);
    }

    #[Test]
    public function throwsWhenRateLimitExceeded(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('acquire')
            ->willThrowException(new LockAcquisitionException('rate limit'));

        $middleware = new RateLimit($lock);
        $envelope = $this->createEnvelope(queue: 'high-volume');

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('Rate limit exceeded');

        $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'never');
    }

    #[Test]
    public function lockIsReleasedEvenWhenNextThrows(): void
    {
        $handle = new LockHandle('queue:rate:default', 'token-1', microtime(true), 1);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($handle);
        $lock->expects(self::once())->method('release')->with($handle);

        $middleware = new RateLimit($lock);
        $envelope = $this->createEnvelope();

        try {
            $middleware->handle($envelope, static function (JobEnvelope $e): never {
                throw new RuntimeException('job failed');
            });
        } catch (RuntimeException) {
            // Expected
        }
    }

    #[Test]
    public function customTtlAndTimeoutArePassedToLock(): void
    {
        $handle = new LockHandle('queue:rate:default', 'token-1', microtime(true), 5);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:rate:default', 5, 2000)
            ->willReturn($handle);
        $lock->method('release')->willReturn(true);

        $middleware = new RateLimit($lock, ttlSeconds: 5, timeoutMs: 2000);
        $envelope = $this->createEnvelope();

        $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'ok');
    }

    private function createEnvelope(string $queue = 'default'): JobEnvelope
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
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );
    }
}
