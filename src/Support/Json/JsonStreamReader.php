<?php

declare(strict_types=1);

namespace Pulsar\Support\Json;

use Generator;
use InvalidArgumentException;
use JsonException;
use Pulsar\Api\Api;
use RuntimeException;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function is_resource;
use function json_decode;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Forward-only streaming JSON parser using fread() chunks.
 *
 * Processes arbitrarily large JSON files in constant memory by yielding
 * parsed items as they are found. Supports both standard JSON arrays
 * and NDJSON (newline-delimited JSON) formats.
 */
#[Api(since: '1.0.0')]
final class JsonStreamReader
{
    private const int DEFAULT_CHUNK_SIZE = 8192;
    private const int DEFAULT_MAX_DEPTH = 64;

    /**
     * Stream-parse a JSON array from a file path, yielding each element.
     *
     * Memory usage is bounded by chunk size + largest single item, not file size.
     *
     * @param string $path File path to read
     * @param int<1, max> $chunkSize Read buffer size in bytes
     * @param int<1, max> $maxDepth Maximum nesting depth allowed
     * @return Generator<int, mixed> Yields decoded items
     *
     * @throws InvalidArgumentException If file cannot be opened
     * @throws RuntimeException If JSON structure is invalid
     */
    public function readArray(string $path, int $chunkSize = self::DEFAULT_CHUNK_SIZE, int $maxDepth = self::DEFAULT_MAX_DEPTH): Generator
    {
        $stream = @fopen($path, 'rb');

        if (!is_resource($stream)) {
            throw new InvalidArgumentException("Cannot open file: $path");
        }

        try {
            yield from $this->parseArrayStream($stream, $chunkSize, $maxDepth);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Stream-parse a JSON array from an open stream resource.
     *
     * @param resource $stream Readable stream resource
     * @param int<1, max> $chunkSize Read buffer size in bytes
     * @param int<1, max> $maxDepth Maximum nesting depth allowed
     * @return Generator<int, mixed>
     *
     * @throws RuntimeException If JSON structure is invalid
     */
    public function readArrayFromStream($stream, int $chunkSize = self::DEFAULT_CHUNK_SIZE, int $maxDepth = self::DEFAULT_MAX_DEPTH): Generator
    {
        yield from $this->parseArrayStream($stream, $chunkSize, $maxDepth);
    }

    /**
     * Parse NDJSON (newline-delimited JSON) from a file path.
     *
     * Each line is independently parsed as a JSON value.
     *
     * @param int<1, max> $chunkSize Read buffer size in bytes
     * @return Generator<int, mixed>
     *
     * @throws InvalidArgumentException If file cannot be opened
     * @throws JsonException If a line contains invalid JSON
     */
    public function readNdjson(string $path, int $chunkSize = self::DEFAULT_CHUNK_SIZE): Generator
    {
        $stream = @fopen($path, 'rb');

        if (!is_resource($stream)) {
            throw new InvalidArgumentException("Cannot open file: $path");
        }

        try {
            yield from $this->parseNdjsonStream($stream, $chunkSize);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Parse NDJSON from an open stream resource.
     *
     * @param resource $stream
     * @param int<1, max> $chunkSize
     * @return Generator<int, mixed>
     *
     * @throws JsonException If a line contains invalid JSON
     */
    public function readNdjsonFromStream($stream, int $chunkSize = self::DEFAULT_CHUNK_SIZE): Generator
    {
        yield from $this->parseNdjsonStream($stream, $chunkSize);
    }

    /**
     * @param resource $stream
     * @param int<1, max> $chunkSize
     * @param int<1, max> $maxDepth
     * @return Generator<int, mixed>
     */
    private function parseArrayStream($stream, int $chunkSize, int $maxDepth): Generator
    {
        $buffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $started = false;
        $itemStart = -1;
        $index = 0;
        $resumePos = 0;

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            $pos = $resumePos;
            $len = strlen($buffer);

            while ($pos < $len) {
                $char = $buffer[$pos];

                if ($escaped) {
                    $escaped = false;
                    $pos++;
                    continue;
                }

                if ($char === '\\' && $inString) {
                    $escaped = true;
                    $pos++;
                    continue;
                }

                if ($char === '"') {
                    $inString = !$inString;
                    $pos++;
                    continue;
                }

                if ($inString) {
                    $pos++;
                    continue;
                }

                if (!$started) {
                    if ($char === '[') {
                        $started = true;
                        $pos++;
                        continue;
                    }

                    if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                        $pos++;
                        continue;
                    }

                    throw new RuntimeException('Expected JSON array (opening "[")');
                }

                if ($char === '{' || $char === '[') {
                    if ($depth === 0) {
                        $itemStart = $pos;
                    }

                    $depth++;

                    if ($depth > $maxDepth) {
                        throw new RuntimeException("JSON nesting exceeds maximum depth of $maxDepth");
                    }

                    $pos++;
                    continue;
                }

                // Handle scalar values at root array level (strings, numbers, booleans, null)
                // Must check BEFORE close bracket/brace handling to yield pending scalars
                if ($depth === 0 && $itemStart >= 0 && ($char === ',' || $char === ']')) {
                    $itemJson = trim(substr($buffer, $itemStart, $pos - $itemStart));

                    if ($itemJson !== '') {
                        try {
                            yield $index++ => json_decode($itemJson, true, $maxDepth, JSON_THROW_ON_ERROR);
                        } catch (JsonException $e) {
                            throw new RuntimeException("Invalid JSON at item $index: " . $e->getMessage(), 0, $e);
                        }
                    }

                    $itemStart = -1;

                    if ($char === ']') {
                        return;
                    }

                    $buffer = substr($buffer, $pos + 1);
                    $len = strlen($buffer);
                    $pos = 0;
                    continue;
                }

                if ($char === '}' || $char === ']') {
                    $depth--;

                    if ($depth === 0 && $itemStart >= 0) {
                        $itemJson = substr($buffer, $itemStart, $pos - $itemStart + 1);

                        try {
                            yield $index++ => json_decode($itemJson, true, $maxDepth, JSON_THROW_ON_ERROR);
                        } catch (JsonException $e) {
                            throw new RuntimeException("Invalid JSON at item $index: " . $e->getMessage(), 0, $e);
                        }

                        $itemStart = -1;
                        $buffer = substr($buffer, $pos + 1);
                        $len = strlen($buffer);
                        $pos = 0;
                        continue;
                    }

                    if ($depth < 0) {
                        // End of root array
                        return;
                    }

                    $pos++;
                    continue;
                }

                if ($depth === 0 && $itemStart < 0 && $char !== ',' && $char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                    $itemStart = $pos;
                }

                $pos++;
            }

            // Keep only unprocessed data in buffer for next chunk
            if ($itemStart >= 0) {
                $buffer = substr($buffer, $itemStart);
                $itemStart = 0;
                $resumePos = strlen($buffer);
            } elseif ($depth === 0) {
                $buffer = '';
                $resumePos = 0;
            } else {
                // Inside an item but itemStart already at 0: keep entire buffer
                $resumePos = strlen($buffer);
            }
        }
    }

    /**
     * @param resource $stream
     * @param int<1, max> $chunkSize
     * @return Generator<int, mixed>
     */
    private function parseNdjsonStream($stream, int $chunkSize): Generator
    {
        $buffer = '';
        $index = 0;

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '') {
                    continue;
                }

                yield $index++ => json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        // Handle final line without trailing newline
        $remaining = trim($buffer);

        if ($remaining !== '') {
            yield $index => json_decode($remaining, true, 512, JSON_THROW_ON_ERROR);
        }
    }
}
