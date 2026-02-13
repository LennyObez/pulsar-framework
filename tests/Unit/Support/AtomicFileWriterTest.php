<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\AtomicFileWriter;
use RuntimeException;

use function file_get_contents;
use function filesize;
use function hash;
use function hash_file;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(AtomicFileWriter::class)]
final class AtomicFileWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_atomic_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up any files created during tests
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function it_writes_content_to_new_file(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'test.txt';

        AtomicFileWriter::write($path, 'hello world');

        self::assertFileExists($path);
        self::assertSame('hello world', file_get_contents($path));
    }

    #[Test]
    public function it_overwrites_existing_file(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'test.txt';

        AtomicFileWriter::write($path, 'first');
        AtomicFileWriter::write($path, 'second');

        self::assertSame('second', file_get_contents($path));
    }

    #[Test]
    public function it_writes_empty_content(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'empty.txt';

        AtomicFileWriter::write($path, '');

        self::assertFileExists($path);
        self::assertSame('', file_get_contents($path));
    }

    #[Test]
    public function it_throws_when_parent_directory_missing(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent' . DIRECTORY_SEPARATOR . 'file.txt';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write temporary file');

        AtomicFileWriter::write($path, 'content');
    }

    #[Test]
    public function it_leaves_no_temp_files_on_successful_write(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'clean.txt';

        AtomicFileWriter::write($path, 'content');

        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '.tmp.*');
        self::assertSame([], $files ?: []);
    }

    #[Test]
    public function it_handles_binary_content(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'binary.bin';
        $binary = random_bytes(256);

        AtomicFileWriter::write($path, $binary);

        self::assertSame($binary, file_get_contents($path));
    }

    #[Test]
    public function it_handles_large_content(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'large.txt';
        $size = 1024 * 1024; // 1 MB
        $content = str_repeat('x', $size);

        AtomicFileWriter::write($path, $content);

        // Compare via hash to avoid holding two 1 MB copies in memory
        // (the full suite accumulates ~120 MB before reaching this test).
        $expectedHash = hash('sha256', $content);
        unset($content);

        self::assertSame($size, filesize($path));
        self::assertSame($expectedHash, hash_file('sha256', $path));
    }
}
