<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Exception\AiStreamException;
use Pulsar\AI\Streaming\NdJsonReader;
use Pulsar\AI\Streaming\SseEvent;
use Pulsar\AI\Streaming\SseReader;

use function array_map;
use function iterator_to_array;

/**
 * Framing, on its own.
 *
 * Both readers exist because transport chunks are cut wherever TCP cut them,
 * which is never where the protocol's boundaries are. The cases below are the
 * ones that actually bite: a CRLF split between its two bytes, a keep-alive
 * comment, and a multi-line `data:` payload.
 */
#[CoversClass(SseReader::class)]
#[CoversClass(SseEvent::class)]
#[CoversClass(NdJsonReader::class)]
final class SseReaderTest extends TestCase
{
    #[Test]
    public function framesSurviveAChunkBoundaryBetweenTheCarriageReturnAndItsLineFeed(): void
    {
        // The frame terminator here is "\r\n\r\n" and the chunks split it twice.
        $chunks = [
            "event: message\r\ndata: {\"a\":1}\r",
            "\n\r",
            "\nevent: message\r\ndata: {\"a\":2}\r\n\r\n",
        ];

        $events = iterator_to_array(SseReader::events($chunks), false);

        self::assertSame(['message', 'message'], array_map(static fn(SseEvent $e): string => $e->name, $events));
        self::assertSame(['{"a":1}', '{"a":2}'], array_map(static fn(SseEvent $e): string => $e->data, $events));
    }

    #[Test]
    public function commentKeepAlivesProduceNoEvent(): void
    {
        $chunks = [": keep-alive\n\n", "data: payload\n\n", ":\n\n"];

        $events = iterator_to_array(SseReader::events($chunks), false);

        self::assertCount(1, $events);
        self::assertSame('payload', $events[0]->data);
    }

    #[Test]
    public function multipleDataLinesInOneFrameAreJoinedWithNewlines(): void
    {
        $chunks = ["event: chunk\ndata: first\ndata: second\n\n"];

        $events = iterator_to_array(SseReader::events($chunks), false);

        self::assertCount(1, $events);
        self::assertSame("first\nsecond", $events[0]->data);
    }

    #[Test]
    public function aFieldWithoutASpaceAfterTheColonKeepsEveryByteOfItsValue(): void
    {
        // Only ONE leading space is part of the framing; a second one is data.
        $chunks = ["data:tight\n\n", "data:  padded\n\n"];

        $events = iterator_to_array(SseReader::events($chunks), false);

        self::assertSame('tight', $events[0]->data);
        self::assertSame(' padded', $events[1]->data);
    }

    #[Test]
    public function aFinalFrameThatNeverGotItsBlankLineIsStillHandedOn(): void
    {
        // A server can close politely right after writing the last frame. The
        // frame is intact, so dropping it would invent a truncation.
        $chunks = ["data: {\"done\":true}\n"];

        $events = iterator_to_array(SseReader::events($chunks), false);

        self::assertCount(1, $events);
        self::assertSame('{"done":true}', $events[0]->data);
    }

    #[Test]
    public function ndJsonObjectsSurviveChunkBoundariesInsideThem(): void
    {
        $chunks = ['{"a":', '1}' . "\n" . '{"b', '":2}' . "\n"];

        $objects = iterator_to_array(new NdJsonReader()->objects($chunks), false);

        self::assertSame([['a' => 1], ['b' => 2]], $objects);
    }

    #[Test]
    public function anNdJsonLineThatIsNotAnObjectFailsRatherThanBeingSkipped(): void
    {
        $reader = new NdJsonReader('ollama');

        $this->expectException(AiStreamException::class);
        $this->expectExceptionMessageIsOrContains('cut mid-object');

        iterator_to_array($reader->objects(['{"a":1}' . "\n" . '{"b":']), false);
    }
}
