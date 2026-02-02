<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use Pulsar\Observability\Log\Sink\FileSink;

#[CoversClass(FileSink::class)]
final class FileSinkTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_filesink_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $this->cleanDir($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function writesJsonLine(): void
    {
        $path = $this->tempDir . '/test.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Info, 'test message');
        $sink->write($entry);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        $data = json_decode(trim($contents), true);
        self::assertIsArray($data);
        self::assertSame('info', $data['level']);
        self::assertSame('test message', $data['message']);
    }

    #[Test]
    public function createsDirectory(): void
    {
        $path = $this->tempDir . '/nested/dir/test.log';
        $sink = new FileSink($path);

        $entry = LogEntry::create(LogLevel::Debug, 'mkdir test');
        $sink->write($entry);

        self::assertFileExists($path);
    }

    #[Test]
    public function appendsToExistingFile(): void
    {
        $path = $this->tempDir . '/append.log';
        $sink = new FileSink($path);

        $entry1 = LogEntry::create(LogLevel::Info, 'first');
        $entry2 = LogEntry::create(LogLevel::Warning, 'second');

        $sink->write($entry1);
        $sink->write($entry2);

        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        $lines = array_filter(explode("\n", trim($contents)));
        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('first', $first['message']);
        self::assertSame('second', $second['message']);
    }
}
