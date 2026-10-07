<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Command\Console\EventDataExtractor;

#[CoversClass(EventDataExtractor::class)]
final class EventDataExtractorTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsStringValue(): void
    {
        $extractor = new EventDataExtractor(['event_type' => 'http.request']);

        self::assertSame('http.request', $extractor->eventType());
    }

    #[Test]
    public function eventTypeDefaultsToUnknown(): void
    {
        $extractor = new EventDataExtractor([]);

        self::assertSame('unknown', $extractor->eventType());
    }

    #[Test]
    public function eventTypeReturnsUnknownForNonString(): void
    {
        $extractor = new EventDataExtractor(['event_type' => 123]);

        self::assertSame('unknown', $extractor->eventType());
    }

    #[Test]
    public function eventIdReturnsStringValue(): void
    {
        $extractor = new EventDataExtractor(['event_id' => 'evt-abc']);

        self::assertSame('evt-abc', $extractor->eventId());
    }

    #[Test]
    public function eventIdDefaultsToEmptyString(): void
    {
        $extractor = new EventDataExtractor([]);

        self::assertSame('', $extractor->eventId());
    }

    #[Test]
    public function timestampUsReturnsIntValue(): void
    {
        $extractor = new EventDataExtractor(['timestamp_us' => 1700000000000000]);

        self::assertSame(1700000000000000, $extractor->timestampUs());
    }

    #[Test]
    public function timestampUsConvertsNumericString(): void
    {
        $extractor = new EventDataExtractor(['timestamp_us' => '1700000000000000']);

        self::assertSame(1700000000000000, $extractor->timestampUs());
    }

    #[Test]
    public function timestampUsDefaultsToZero(): void
    {
        $extractor = new EventDataExtractor([]);

        self::assertSame(0, $extractor->timestampUs());
    }

    #[Test]
    public function requestIdReturnsStringValue(): void
    {
        $extractor = new EventDataExtractor(['request_id' => 'req-1']);

        self::assertSame('req-1', $extractor->requestId());
    }

    #[Test]
    public function idReturnsIntValue(): void
    {
        $extractor = new EventDataExtractor(['id' => 42]);

        self::assertSame(42, $extractor->id());
    }

    #[Test]
    public function formattedTimeFormatsTimestamp(): void
    {
        // 1700000000 seconds = 2023-11-14 22:13:20 UTC
        $extractor = new EventDataExtractor(['timestamp_us' => 1700000000000000]);

        $formatted = $extractor->formattedTime('Y-m-d');

        self::assertSame('2023-11-14', $formatted);
    }
}
