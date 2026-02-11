<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Internal\JsonLinesFileWriter;
use RuntimeException;

#[CoversClass(JsonLinesFileWriter::class)]
final class JsonLinesFileWriterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'jlwriter_') ?: sys_get_temp_dir() . '/jlwriter_test';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function writesDataToFile(): void
    {
        $writer = new JsonLinesFileWriter($this->tempFile);

        $writer->write("{\"level\":\"info\"}\n");

        $content = file_get_contents($this->tempFile);
        self::assertSame("{\"level\":\"info\"}\n", $content);
    }

    #[Test]
    public function appendsMultipleWrites(): void
    {
        $writer = new JsonLinesFileWriter($this->tempFile);

        $writer->write("{\"a\":1}\n");
        $writer->write("{\"b\":2}\n");

        $content = file_get_contents($this->tempFile);
        self::assertSame("{\"a\":1}\n{\"b\":2}\n", $content);
    }

    #[Test]
    public function throwsOnInvalidPath(): void
    {
        $writer = new JsonLinesFileWriter('/nonexistent/path/file.jsonl');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to open file');

        @$writer->write('data');
    }
}
