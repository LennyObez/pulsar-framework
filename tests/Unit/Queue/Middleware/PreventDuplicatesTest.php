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
use Pulsar\Queue\Middleware\PreventDuplicates;

#[CoversClass(PreventDuplicates::class)]
final class PreventDuplicatesTest extends TestCase
{
    #[Test]
    public function jobWithEmptyIdempotencyKeyPassesThrough(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $middleware = new PreventDuplicates($lock);
        $envelope = $this->createEnvelope(idempotencyKey: '');

        $result = $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'passed');

        self::assertSame('passed', $result);
    }

    #[Test]
    public function jobWithIdempotencyKeyAcquiresLockAndPasses(): void
    {
        $handle = new LockHandle('queue:dedup:my-key', 'token-1', microtime(true), 300);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:dedup:my-key', 300)
            ->willReturn($handle);
        $lock->expects(self::once())
            ->method('release')
            ->with($handle);

        $middleware = new PreventDuplicates($lock);
        $envelope = $this->createEnvelope(idempotencyKey: 'my-key');

        $result = $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'processed');

        self::assertSame('processed', $result);
    }

    #[Test]
    public function duplicateJobThrowsQueueException(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('acquire')
            ->willThrowException(new LockAcquisitionException('already held'));

        $middleware = new PreventDuplicates($lock);
        $envelope = $this->createEnvelope(idempotencyKey: 'dup-key');

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Duplicate job');

        $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'should-not-reach');
    }

    #[Test]
    public function lockIsReleasedEvenWhenNextThrows(): void
    {
        $handle = new LockHandle('queue:dedup:key', 'token-1', microtime(true), 300);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($handle);
        $lock->expects(self::once())->method('release')->with($handle);

        $middleware = new PreventDuplicates($lock);
        $envelope = $this->createEnvelope(idempotencyKey: 'key');

        try {
            $middleware->handle($envelope, static function (JobEnvelope $e): never {
                throw new \RuntimeException('job failed');
            });
        } catch (\RuntimeException) {
            // Expected — lock release still called
        }
    }

    #[Test]
    public function customTtlIsRespected(): void
    {
        $handle = new LockHandle('queue:dedup:key', 'token-1', microtime(true), 600);
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with('queue:dedup:key', 600)
            ->willReturn($handle);
        $lock->method('release')->willReturn(true);

        $middleware = new PreventDuplicates($lock, ttlSeconds: 600);
        $envelope = $this->createEnvelope(idempotencyKey: 'key');

        $middleware->handle($envelope, static fn(JobEnvelope $e): string => 'ok');
    }

    private function createEnvelope(string $idempotencyKey = ''): JobEnvelope
    {
        return new JobEnvelope(
            id: 'job-001',
            jobClass: 'App\\Jobs\\Test',
            payload: '{}',
            queue: 'default',
            idempotencyKey: $idempotencyKey,
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
