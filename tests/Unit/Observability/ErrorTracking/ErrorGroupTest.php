<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\ErrorTracking\ErrorGroup;
use RuntimeException;

#[CoversClass(ErrorGroup::class)]
final class ErrorGroupTest extends TestCase
{
    #[Test]
    public function recordIncrementsCount(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));
        $event = ErrorEvent::fromThrowable(new RuntimeException('test'));

        $group->record($event);

        self::assertSame(1, $group->occurrenceCount());
    }

    #[Test]
    public function recordUpdatesLastSeen(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));

        $event1 = ErrorEvent::fromThrowable(new RuntimeException('first'));
        $group->record($event1);
        $firstLastSeen = $group->lastSeen();

        $event2 = ErrorEvent::fromThrowable(new RuntimeException('second'));
        $group->record($event2);

        self::assertGreaterThanOrEqual($firstLastSeen, $group->lastSeen());
        self::assertSame(2, $group->occurrenceCount());
    }

    #[Test]
    public function capsRecentEvents(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'), maxRecentEvents: 2);

        for ($i = 0; $i < 5; $i++) {
            $group->record(ErrorEvent::fromThrowable(new RuntimeException("error $i")));
        }

        self::assertSame(5, $group->occurrenceCount());
        self::assertCount(2, $group->recentEvents());
    }

    #[Test]
    public function exposesLatestEventDetails(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));
        $group->record(ErrorEvent::fromThrowable(new RuntimeException('latest')));

        self::assertSame(RuntimeException::class, $group->exceptionClass());
        self::assertSame('latest', $group->message());
    }

    #[Test]
    public function exceptionClassReturnsNullWhenEmpty(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));

        self::assertNull($group->exceptionClass());
    }

    #[Test]
    public function messageReturnsNullWhenEmpty(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));

        self::assertNull($group->message());
    }

    #[Test]
    public function firstSeenIsSetOnConstruction(): void
    {
        $group = new ErrorGroup(new ErrorFingerprint('abc'));

        self::assertInstanceOf(DateTimeImmutable::class, $group->firstSeen());
    }
}
