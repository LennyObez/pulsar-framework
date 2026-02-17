<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Command\Console;

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
        $extractor = new EventDataExtractor(['event_type' => 'http.response']);

        self::assertSame('http.response', $extractor->eventType());
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
        $extractor = new EventDataExtractor(['event_type' => 42]);

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
    public function timestampUsParsesNumericString(): void
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
    public function timestampUsReturnsZeroForNonNumeric(): void
    {
        $extractor = new EventDataExtractor(['timestamp_us' => 'not-a-number']);

        self::assertSame(0, $extractor->timestampUs());
    }

    #[Test]
    public function requestIdReturnsValue(): void
    {
        $extractor = new EventDataExtractor(['request_id' => 'req-123']);

        self::assertSame('req-123', $extractor->requestId());
    }

    #[Test]
    public function requestIdDefaultsToEmptyString(): void
    {
        self::assertSame('', new EventDataExtractor([])->requestId());
    }

    #[Test]
    public function idReturnsIntValue(): void
    {
        $extractor = new EventDataExtractor(['id' => 42]);

        self::assertSame(42, $extractor->id());
    }

    #[Test]
    public function idParsesNumericString(): void
    {
        $extractor = new EventDataExtractor(['id' => '99']);

        self::assertSame(99, $extractor->id());
    }

    #[Test]
    public function formattedTimeReturnsDateString(): void
    {
        // 1700000000 microseconds = 1.7 seconds
        $extractor = new EventDataExtractor(['timestamp_us' => 1700000000_000000]);

        $time = $extractor->formattedTime('Y-m-d');

        self::assertSame('2023-11-14', $time);
    }

    #[Test]
    public function formattedTimeWithZeroTimestamp(): void
    {
        $extractor = new EventDataExtractor([]);

        $time = $extractor->formattedTime('Y-m-d');

        self::assertSame('1970-01-01', $time);
    }
}
