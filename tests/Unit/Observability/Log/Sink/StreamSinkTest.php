<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\Sink\StreamSink;

use function count;

#[CoversClass(StreamSink::class)]
final class StreamSinkTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_streamsink_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function writesToStream(): void
    {
        // Use a temp file as a writable stream
        $path = $this->tempDir . '/stream_output.log';
        file_put_contents($path, '');

        $sink = new StreamSink($path);

        $entry = LogEntry::create(LogLevel::Error, 'stream test');
        $sink->write($entry);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        $data = json_decode(trim($contents), true);
        self::assertIsArray($data);
        self::assertSame('error', $data['level']);
        self::assertSame('stream test', $data['message']);
    }

    #[Test]
    public function throwsForInvalidStream(): void
    {
        $this->expectException(LogException::class);

        new StreamSink('invalid://nonexistent_stream_uri');
    }

    #[Test]
    public function destructorClosesOwnedFileStream(): void
    {
        $path = $this->tempDir . '/owned_stream.log';
        file_put_contents($path, '');

        $before = count(get_resources('stream'));

        $sink = new StreamSink($path);

        self::assertSame($before + 1, count(get_resources('stream')), 'sink should hold one open stream');

        // Dropping the only reference triggers __destruct deterministically.
        unset($sink);

        self::assertSame($before, count(get_resources('stream')), 'descriptor must be released on destruction');
    }

    #[Test]
    public function destructorDoesNotCloseStandardStreams(): void
    {
        // Closing php://stderr would tear down the process's error stream for
        // every other consumer, so a StreamSink over it must never own/close it.
        $sink = new StreamSink('php://stderr');

        unset($sink);

        // stderr is still usable: opening it again succeeds (it would fail if
        // the underlying descriptor had been closed).
        $reopened = fopen('php://stderr', 'a');
        self::assertIsResource($reopened);
    }
}
