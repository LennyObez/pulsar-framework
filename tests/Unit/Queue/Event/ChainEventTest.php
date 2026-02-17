<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Event\ChainCompleted;
use Pulsar\Queue\Event\ChainFailed;
use ReflectionClass;

#[CoversClass(ChainCompleted::class)]
#[CoversClass(ChainFailed::class)]
final class ChainEventTest extends TestCase
{
    #[Test]
    public function chainCompletedHoldsAllProperties(): void
    {
        $event = new ChainCompleted(
            chainId: 'chain-001',
            totalJobs: 5,
            occurredAt: 1709827200,
        );

        self::assertSame('chain-001', $event->chainId);
        self::assertSame(5, $event->totalJobs);
        self::assertSame(1709827200, $event->occurredAt);
    }

    #[Test]
    public function chainCompletedWithSingleJob(): void
    {
        $event = new ChainCompleted(
            chainId: 'chain-single',
            totalJobs: 1,
            occurredAt: 1709827300,
        );

        self::assertSame(1, $event->totalJobs);
    }

    #[Test]
    public function chainFailedHoldsAllProperties(): void
    {
        $event = new ChainFailed(
            chainId: 'chain-002',
            failedAtIndex: 2,
            failedJobId: 'job-003',
            failedJobClass: 'App\\Jobs\\ProcessPayment',
            totalJobs: 4,
            occurredAt: 1709827400,
        );

        self::assertSame('chain-002', $event->chainId);
        self::assertSame(2, $event->failedAtIndex);
        self::assertSame('job-003', $event->failedJobId);
        self::assertSame('App\\Jobs\\ProcessPayment', $event->failedJobClass);
        self::assertSame(4, $event->totalJobs);
        self::assertSame(1709827400, $event->occurredAt);
    }

    #[Test]
    public function chainFailedAtFirstJob(): void
    {
        $event = new ChainFailed(
            chainId: 'chain-003',
            failedAtIndex: 0,
            failedJobId: 'job-001',
            failedJobClass: 'App\\Jobs\\ValidateOrder',
            totalJobs: 3,
            occurredAt: 1709827500,
        );

        self::assertSame(0, $event->failedAtIndex);
    }

    #[Test]
    public function chainFailedAtLastJob(): void
    {
        $event = new ChainFailed(
            chainId: 'chain-004',
            failedAtIndex: 9,
            failedJobId: 'job-010',
            failedJobClass: 'App\\Jobs\\FinalStep',
            totalJobs: 10,
            occurredAt: 1709827600,
        );

        self::assertSame(9, $event->failedAtIndex);
        self::assertSame(10, $event->totalJobs);
    }

    #[Test]
    public function chainCompletedIsReadonly(): void
    {
        $event = new ChainCompleted(chainId: 'c', totalJobs: 1, occurredAt: 0);

        $reflection = new ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function chainFailedIsReadonly(): void
    {
        $event = new ChainFailed(
            chainId: 'c',
            failedAtIndex: 0,
            failedJobId: 'j',
            failedJobClass: 'C',
            totalJobs: 1,
            occurredAt: 0,
        );

        $reflection = new ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
