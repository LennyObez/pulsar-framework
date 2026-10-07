<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Cardinality\OverflowTracker;

#[CoversClass(OverflowTracker::class)]
final class OverflowTrackerTest extends TestCase
{
    #[Test]
    public function newKeyReturnsTrueOnFirstOccurrence(): void
    {
        $tracker = new OverflowTracker();

        self::assertTrue($tracker->track('key1'));
        self::assertSame(1, $tracker->count());
    }

    #[Test]
    public function duplicateKeyReturnsFalse(): void
    {
        $tracker = new OverflowTracker();

        self::assertTrue($tracker->track('key1'));
        self::assertFalse($tracker->track('key1'));
        self::assertSame(1, $tracker->count());
    }

    #[Test]
    public function capacityLimitIsRespected(): void
    {
        $tracker = new OverflowTracker(maxSize: 3);

        self::assertTrue($tracker->track('a'));
        self::assertTrue($tracker->track('b'));
        self::assertTrue($tracker->track('c'));
        self::assertTrue($tracker->isFull());

        // At capacity: new key should be rejected
        self::assertFalse($tracker->track('d'));
        self::assertSame(3, $tracker->count());
    }

    #[Test]
    public function isFullReportsCorrectly(): void
    {
        $tracker = new OverflowTracker(maxSize: 2);

        self::assertFalse($tracker->isFull());

        $tracker->track('a');
        self::assertFalse($tracker->isFull());

        $tracker->track('b');
        self::assertTrue($tracker->isFull());
    }

    #[Test]
    public function existingKeysStillWorkWhenFull(): void
    {
        $tracker = new OverflowTracker(maxSize: 1);

        self::assertTrue($tracker->track('only'));
        self::assertTrue($tracker->isFull());

        // Existing key still returns false (already tracked, not newly added)
        self::assertFalse($tracker->track('only'));
        // New key is rejected
        self::assertFalse($tracker->track('other'));
    }

    #[Test]
    public function emptyTrackerHasZeroCount(): void
    {
        $tracker = new OverflowTracker();

        self::assertSame(0, $tracker->count());
        self::assertFalse($tracker->isFull());
    }
}
