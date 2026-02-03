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
}
