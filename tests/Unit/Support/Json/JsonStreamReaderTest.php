<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Json;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Json\JsonStreamReader;
use RuntimeException;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(JsonStreamReader::class)]
final class JsonStreamReaderTest extends TestCase
{
    private string $tempFile;
    private JsonStreamReader $reader;

    protected function setUp(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'json_stream_');
        self::assertIsString($tempFile);
        $this->tempFile = $tempFile;
        $this->reader = new JsonStreamReader();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testReadArrayParsesSimpleArray(): void
    {
        file_put_contents($this->tempFile, '[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]');

        $results = [];
        foreach ($this->reader->readArray($this->tempFile) as $item) {
            $results[] = $item;
        }

        self::assertCount(2, $results);
        self::assertSame(['id' => 1, 'name' => 'Alice'], $results[0]);
        self::assertSame(['id' => 2, 'name' => 'Bob'], $results[1]);
    }

    public function testReadArrayHandlesEmptyArray(): void
    {
        file_put_contents($this->tempFile, '[]');

        $results = iterator_to_array($this->reader->readArray($this->tempFile));

        self::assertSame([], $results);
    }

    public function testReadArrayHandlesNestedObjects(): void
    {
        $data = [
            ['id' => 1, 'meta' => ['tags' => ['a', 'b'], 'nested' => ['deep' => true]]],
        ];
        file_put_contents($this->tempFile, json_encode($data));

        $results = iterator_to_array($this->reader->readArray($this->tempFile));

        self::assertCount(1, $results);
        $item0 = $results[0];
        self::assertIsArray($item0);
        $meta = $item0['meta'];
        self::assertIsArray($meta);
        self::assertSame(['a', 'b'], $meta['tags']);
        $nested = $meta['nested'];
        self::assertIsArray($nested);
        self::assertTrue($nested['deep']);
    }

    public function testReadArrayHandlesStringsWithSpecialCharacters(): void
    {
        $data = [['msg' => 'He said "hello, world" and left [brackets]']];
        file_put_contents($this->tempFile, json_encode($data));

        $results = iterator_to_array($this->reader->readArray($this->tempFile));

        $item0 = $results[0];
        self::assertIsArray($item0);
        self::assertSame('He said "hello, world" and left [brackets]', $item0['msg']);
    }

    public function testReadArrayWithMultipleChunks(): void
    {
        // Generate data large enough to require multiple reads at default chunk size
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = ['index' => $i, 'value' => str_repeat('x', 100)];
        }
        file_put_contents($this->tempFile, json_encode($data));

        // Chunk size smaller than each item ensures multi-chunk parsing
        $results = iterator_to_array($this->reader->readArray($this->tempFile, chunkSize: 4096));

        self::assertCount(100, $results);
        $item0 = $results[0];
        self::assertIsArray($item0);
        self::assertSame(0, $item0['index']);
        $item99 = $results[99];
        self::assertIsArray($item99);
        self::assertSame(99, $item99['index']);
    }

    public function testReadArrayThrowsOnInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($this->reader->readArray('/nonexistent/path/to/file.json'));
    }

    public function testReadArrayThrowsOnNonArrayRoot(): void
    {
        file_put_contents($this->tempFile, '{"key": "value"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected JSON array');

        iterator_to_array($this->reader->readArray($this->tempFile));
    }

    public function testReadArrayRespectsMaxDepth(): void
    {
        // Create deeply nested structure
        $nested = '[[[[[[[[[[1]]]]]]]]]]';
        file_put_contents($this->tempFile, "[$nested]");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nesting exceeds maximum depth');

        iterator_to_array($this->reader->readArray($this->tempFile, maxDepth: 3));
    }

    public function testReadArrayFromStream(): void
    {
        file_put_contents($this->tempFile, '[1, 2, 3]');
        $stream = fopen($this->tempFile, 'rb');
        self::assertIsResource($stream);

        $results = iterator_to_array($this->reader->readArrayFromStream($stream));
        fclose($stream);

        self::assertSame([1, 2, 3], array_values($results));
    }

    public function testReadNdjsonParsesLines(): void
    {
        $lines = '{"id":1,"name":"Alice"}' . "\n" . '{"id":2,"name":"Bob"}' . "\n";
        file_put_contents($this->tempFile, $lines);

        $results = iterator_to_array($this->reader->readNdjson($this->tempFile));

        self::assertCount(2, $results);
        $item0 = $results[0];
        self::assertIsArray($item0);
        self::assertSame('Alice', $item0['name']);
        $item1 = $results[1];
        self::assertIsArray($item1);
        self::assertSame('Bob', $item1['name']);
    }

    public function testReadNdjsonSkipsEmptyLines(): void
    {
        $lines = '{"a":1}' . "\n\n\n" . '{"b":2}' . "\n";
        file_put_contents($this->tempFile, $lines);

        $results = iterator_to_array($this->reader->readNdjson($this->tempFile));

        self::assertCount(2, $results);
    }

    public function testReadNdjsonHandlesLastLineWithoutNewline(): void
    {
        file_put_contents($this->tempFile, '{"id":1}');

        $results = iterator_to_array($this->reader->readNdjson($this->tempFile));

        self::assertCount(1, $results);
        $item0 = $results[0];
        self::assertIsArray($item0);
        self::assertSame(1, $item0['id']);
    }

    public function testReadNdjsonThrowsOnInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($this->reader->readNdjson('/nonexistent/file.ndjson'));
    }

    public function testReadNdjsonFromStream(): void
    {
        file_put_contents($this->tempFile, '{"a":1}' . "\n" . '{"b":2}');
        $stream = fopen($this->tempFile, 'rb');
        self::assertIsResource($stream);

        $results = iterator_to_array($this->reader->readNdjsonFromStream($stream));
        fclose($stream);

        self::assertCount(2, $results);
    }

    public function testReadArrayHandlesEscapedStrings(): void
    {
        file_put_contents($this->tempFile, '[{"text":"line1\\nline2"},{"text":"tab\\there"}]');

        /** @var list<array<string, mixed>> $results */
        $results = iterator_to_array($this->reader->readArray($this->tempFile));

        self::assertCount(2, $results);
        self::assertSame("line1\nline2", $results[0]['text']);
    }

    public function testReadArrayPreservesIntegerKeys(): void
    {
        file_put_contents($this->tempFile, '[10, 20, 30]');

        $results = [];
        foreach ($this->reader->readArray($this->tempFile) as $key => $value) {
            $results[$key] = $value;
        }

        self::assertSame([0 => 10, 1 => 20, 2 => 30], $results);
    }

    public function testReadArrayHandlesWhitespace(): void
    {
        file_put_contents($this->tempFile, "  \n  [\n  { \"a\" : 1 }\n  ]\n  ");

        /** @var list<array<string, mixed>> $results */
        $results = iterator_to_array($this->reader->readArray($this->tempFile));

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['a']);
    }
}
