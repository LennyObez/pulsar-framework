<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Event\JobCompleted;
use Pulsar\Queue\Event\JobDispatched;
use Pulsar\Queue\Event\JobFailed;
use Pulsar\Queue\Event\JobRetried;
use Pulsar\Queue\Event\QueueEvent;

#[CoversClass(QueueEvent::class)]
#[CoversClass(JobCompleted::class)]
#[CoversClass(JobDispatched::class)]
#[CoversClass(JobFailed::class)]
#[CoversClass(JobRetried::class)]
final class QueueEventTest extends TestCase
{
    #[Test]
    public function jobCompletedHoldsAllProperties(): void
    {
        $event = new JobCompleted(
            jobId: 'job-001',
            queue: 'payments',
            jobClass: 'App\\Jobs\\ProcessPayment',
            occurredAt: 1709827200,
            attempt: 2,
            durationMs: 345.67,
        );

        self::assertSame('job-001', $event->jobId);
        self::assertSame('payments', $event->queue);
        self::assertSame('App\\Jobs\\ProcessPayment', $event->jobClass);
        self::assertSame(1709827200, $event->occurredAt);
        self::assertSame(2, $event->attempt);
        self::assertSame(345.67, $event->durationMs);
    }

    #[Test]
    public function jobDispatchedHoldsAllProperties(): void
    {
        $event = new JobDispatched(
            jobId: 'job-002',
            queue: 'emails',
            jobClass: 'App\\Jobs\\SendEmail',
            occurredAt: 1709827300,
            delaySeconds: 60,
        );

        self::assertSame('job-002', $event->jobId);
        self::assertSame('emails', $event->queue);
        self::assertSame('App\\Jobs\\SendEmail', $event->jobClass);
        self::assertSame(1709827300, $event->occurredAt);
        self::assertSame(60, $event->delaySeconds);
    }

    #[Test]
    public function jobFailedHoldsAllProperties(): void
    {
        $event = new JobFailed(
            jobId: 'job-003',
            queue: 'reports',
            jobClass: 'App\\Jobs\\GenerateReport',
            occurredAt: 1709827400,
            attempt: 3,
            exceptionClass: 'RuntimeException',
            exceptionMessage: 'Connection timed out',
        );

        self::assertSame('job-003', $event->jobId);
        self::assertSame('reports', $event->queue);
        self::assertSame('App\\Jobs\\GenerateReport', $event->jobClass);
        self::assertSame(1709827400, $event->occurredAt);
        self::assertSame(3, $event->attempt);
        self::assertSame('RuntimeException', $event->exceptionClass);
        self::assertSame('Connection timed out', $event->exceptionMessage);
    }

    #[Test]
    public function jobRetriedHoldsAllProperties(): void
    {
        $event = new JobRetried(
            jobId: 'job-004',
            queue: 'default',
            jobClass: 'App\\Jobs\\SyncInventory',
            occurredAt: 1709827500,
            attempt: 1,
            nextAttempt: 2,
            delayMs: 5000,
        );

        self::assertSame('job-004', $event->jobId);
        self::assertSame('default', $event->queue);
        self::assertSame('App\\Jobs\\SyncInventory', $event->jobClass);
        self::assertSame(1709827500, $event->occurredAt);
        self::assertSame(1, $event->attempt);
        self::assertSame(2, $event->nextAttempt);
        self::assertSame(5000, $event->delayMs);
    }
}
