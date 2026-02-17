<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Cardinality\OverflowTracker;

#[CoversClass(OverflowTracker::class)]
final class OverflowTrackerTest extends TestCase
{
    #[Test]
    public function trackNewKeyReturnsTrue(): void
    {
        $tracker = new OverflowTracker(maxSize: 10);

        self::assertTrue($tracker->track('key1'));
    }

    #[Test]
    public function trackDuplicateKeyReturnsFalse(): void
    {
        $tracker = new OverflowTracker(maxSize: 10);
        $tracker->track('key1');

        self::assertFalse($tracker->track('key1'));
    }

    #[Test]
    public function trackBeyondCapacityReturnsFalse(): void
    {
        $tracker = new OverflowTracker(maxSize: 2);
        $tracker->track('key1');
        $tracker->track('key2');

        self::assertFalse($tracker->track('key3'));
    }

    #[Test]
    public function countReflectsTrackedKeys(): void
    {
        $tracker = new OverflowTracker(maxSize: 10);

        self::assertSame(0, $tracker->count());
        $tracker->track('a');
        self::assertSame(1, $tracker->count());
        $tracker->track('b');
        self::assertSame(2, $tracker->count());
        // Duplicate does not increase count
        $tracker->track('a');
        self::assertSame(2, $tracker->count());
    }

    #[Test]
    public function isFullWhenAtCapacity(): void
    {
        $tracker = new OverflowTracker(maxSize: 2);

        self::assertFalse($tracker->isFull());
        $tracker->track('a');
        self::assertFalse($tracker->isFull());
        $tracker->track('b');
        self::assertTrue($tracker->isFull());
    }

    #[Test]
    public function zeroCapacityIsAlwaysFull(): void
    {
        $tracker = new OverflowTracker(maxSize: 0);

        self::assertTrue($tracker->isFull());
        self::assertFalse($tracker->track('anything'));
    }
}
