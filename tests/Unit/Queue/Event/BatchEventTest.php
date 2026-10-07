<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Event\BatchCompleted;
use Pulsar\Queue\Event\BatchFailed;
use Pulsar\Queue\Event\ChainCompleted;
use Pulsar\Queue\Event\ChainFailed;

#[CoversClass(BatchCompleted::class)]
#[CoversClass(BatchFailed::class)]
#[CoversClass(ChainCompleted::class)]
#[CoversClass(ChainFailed::class)]
final class BatchEventTest extends TestCase
{
    #[Test]
    public function batchCompletedHoldsAllProperties(): void
    {
        $event = new BatchCompleted(
            batchId: 'batch-001',
            batchName: 'nightly-reports',
            totalJobs: 50,
            failedJobs: 2,
            occurredAt: 1709827200,
        );

        self::assertSame('batch-001', $event->batchId);
        self::assertSame('nightly-reports', $event->batchName);
        self::assertSame(50, $event->totalJobs);
        self::assertSame(2, $event->failedJobs);
        self::assertSame(1709827200, $event->occurredAt);
    }

    #[Test]
    public function batchFailedHoldsAllProperties(): void
    {
        $event = new BatchFailed(
            batchId: 'batch-002',
            batchName: 'invoice-generation',
            totalJobs: 100,
            failedJobs: 5,
            failedJobId: 'job-042',
            occurredAt: 1709827300,
        );

        self::assertSame('batch-002', $event->batchId);
        self::assertSame('invoice-generation', $event->batchName);
        self::assertSame(100, $event->totalJobs);
        self::assertSame(5, $event->failedJobs);
        self::assertSame('job-042', $event->failedJobId);
        self::assertSame(1709827300, $event->occurredAt);
    }

    #[Test]
    public function chainCompletedHoldsAllProperties(): void
    {
        $event = new ChainCompleted(
            chainId: 'chain-001',
            totalJobs: 5,
            occurredAt: 1709827400,
        );

        self::assertSame('chain-001', $event->chainId);
        self::assertSame(5, $event->totalJobs);
        self::assertSame(1709827400, $event->occurredAt);
    }

    #[Test]
    public function chainFailedHoldsAllProperties(): void
    {
        $event = new ChainFailed(
            chainId: 'chain-002',
            failedAtIndex: 3,
            failedJobId: 'job-099',
            failedJobClass: 'App\\Jobs\\TransformData',
            totalJobs: 7,
            occurredAt: 1709827500,
        );

        self::assertSame('chain-002', $event->chainId);
        self::assertSame(3, $event->failedAtIndex);
        self::assertSame('job-099', $event->failedJobId);
        self::assertSame('App\\Jobs\\TransformData', $event->failedJobClass);
        self::assertSame(7, $event->totalJobs);
        self::assertSame(1709827500, $event->occurredAt);
    }
}
