<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use RuntimeException;

#[CoversClass(ErrorAggregator::class)]
final class ErrorAggregatorTest extends TestCase
{
    #[Test]
    public function captureGroupsByFingerprint(): void
    {
        $aggregator = new ErrorAggregator();
        $exception = new RuntimeException('test');

        $event1 = ErrorEvent::fromThrowable($exception);
        $event2 = ErrorEvent::fromThrowable($exception);

        $aggregator->capture($event1);
        $aggregator->capture($event2);

        self::assertSame(1, $aggregator->count());

        $group = $aggregator->group($event1->fingerprint);
        self::assertNotNull($group);
        self::assertSame(2, $group->occurrenceCount());
    }

    #[Test]
    public function groupsSortedByLastSeenDescending(): void
    {
        $aggregator = new ErrorAggregator();

        $aggregator->capture(ErrorEvent::fromThrowable(new RuntimeException('first')));
        $aggregator->capture(ErrorEvent::fromThrowable(new LogicException('second')));

        $groups = $aggregator->groups();
        self::assertCount(2, $groups);

        // Most recent group should be first
        self::assertSame(LogicException::class, $groups[0]->exceptionClass());
    }

    #[Test]
    public function evictsOldestGroupWhenAtCapacity(): void
    {
        $aggregator = new ErrorAggregator(maxGroups: 2);

        // Create three distinct error types
        $aggregator->capture(ErrorEvent::fromThrowable(new RuntimeException('one')));
        $aggregator->capture(ErrorEvent::fromThrowable(new LogicException('two')));
        $aggregator->capture(ErrorEvent::fromThrowable(new InvalidArgumentException('three')));

        self::assertSame(2, $aggregator->count());
    }

    #[Test]
    public function groupReturnsNullForUnknownFingerprint(): void
    {
        $aggregator = new ErrorAggregator();

        self::assertNull($aggregator->group(new ErrorFingerprint('nonexistent')));
    }

    #[Test]
    public function clearRemovesAllGroups(): void
    {
        $aggregator = new ErrorAggregator();
        $aggregator->capture(ErrorEvent::fromThrowable(new RuntimeException('test')));
        $aggregator->clear();

        self::assertSame(0, $aggregator->count());
        self::assertSame([], $aggregator->groups());
    }
}
