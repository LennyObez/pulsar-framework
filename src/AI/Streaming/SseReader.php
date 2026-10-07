<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Generator;
use Pulsar\Api\Internal;

use function explode;
use function implode;
use function str_replace;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

/**
 * Frames a byte stream into `text/event-stream` events.
 *
 * Anthropic and OpenAI both speak SSE and differ only in what they put inside
 * the frames, so the framing lives here once and the two provider parsers
 * consume {@see SseEvent} objects rather than bytes. Transport chunks split
 * wherever TCP happened to split them, including between the `\r` and `\n` of a
 * CRLF, so the buffer is normalized on every append rather than per chunk.
 *
 * Framing is a pure function of the bytes, so this holds no state and is called
 * statically, the way {@see \Pulsar\Support\Coerce} is. Holding it as an
 * injected property would make each parser depend on a strategy that has no
 * second implementation and nothing to configure.
 */
#[Internal(reason: 'Wire format detail of the SSE providers; consume AiStreamDelta instead')]
final class SseReader
{
    /**
     * @param iterable<int, string> $chunks Raw body pieces from the transport
     *
     * @return Generator<int, SseEvent, mixed, void>
     */
    public static function events(iterable $chunks): Generator
    {
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer = str_replace("\r\n", "\n", $buffer . $chunk);

            while (($break = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 2);

                $event = self::parseFrame($frame);

                if ($event !== null) {
                    yield $event;
                }
            }
        }

        // A frame that never got its blank line is not automatically garbage:
        // a server may close cleanly right after the last one. Hand it on if it
        // parses; the provider parser rejects a payload that was cut mid-JSON,
        // and the accumulator rejects a stream that never reached its terminal
        // event. Guessing here would defeat both.
        if (trim($buffer) === '') {
            return;
        }

        $event = self::parseFrame($buffer);

        if ($event !== null) {
            yield $event;
        }
    }

    private static function parseFrame(string $frame): ?SseEvent
    {
        $name = '';
        $data = [];

        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                // Blank padding and comment keep-alives carry no event.
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);

                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            }

            if ($field === 'event') {
                $name = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }

        if ($name === '' && $data === []) {
            return null;
        }

        return new SseEvent($name, implode("\n", $data));
    }
}
