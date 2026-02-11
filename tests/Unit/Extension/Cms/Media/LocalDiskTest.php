<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\LocalDisk;

#[CoversClass(LocalDisk::class)]
final class LocalDiskTest extends TestCase
{
    private string $tempDir;
    private LocalDisk $disk;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_local_disk_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
        $this->disk = new LocalDisk($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function writeAndReadFile(): void
    {
        $this->disk->write('test.txt', 'Hello World');

        self::assertSame('Hello World', $this->disk->read('test.txt'));
    }

    #[Test]
    public function writeCreatesNestedDirectories(): void
    {
        $this->disk->write('a/b/c/deep.txt', 'nested content');

        self::assertSame('nested content', $this->disk->read('a/b/c/deep.txt'));
    }

    #[Test]
    public function readNonExistentFileThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->read('nonexistent.txt');
    }

    #[Test]
    public function deleteRemovesFile(): void
    {
        $this->disk->write('to-delete.txt', 'gone');
        self::assertTrue($this->disk->exists('to-delete.txt'));

        $this->disk->delete('to-delete.txt');

        self::assertFalse($this->disk->exists('to-delete.txt'));
    }

    #[Test]
    public function deleteNonExistentFileIsNoop(): void
    {
        $this->disk->delete('does-not-exist.txt');

        self::assertFalse($this->disk->exists('does-not-exist.txt'));
    }

    #[Test]
    public function existsReturnsTrueForExistingFile(): void
    {
        $this->disk->write('exists.txt', 'data');

        self::assertTrue($this->disk->exists('exists.txt'));
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->disk->exists('missing.txt'));
    }

    #[Test]
    public function urlReturnsMediaPath(): void
    {
        self::assertSame('/media/images/photo.jpg', $this->disk->url('images/photo.jpg'));
    }

    #[Test]
    public function urlStripsLeadingSlash(): void
    {
        self::assertSame('/media/file.txt', $this->disk->url('/file.txt'));
    }

    // -- Traversal protection -------------------------------------------------

    #[Test]
    public function traversalWithDoubleDotThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->read('../etc/passwd');
    }

    #[Test]
    public function traversalInWriteThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->write('../../evil.txt', 'malicious');
    }

    #[Test]
    public function traversalInDeleteThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->delete('../outside.txt');
    }

    #[Test]
    public function traversalInExistsThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->exists('foo/../../../etc/passwd');
    }

    #[Test]
    public function absoluteUnixPathThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->read('/etc/passwd');
    }

    #[Test]
    public function absoluteWindowsPathThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->read('C:\\Windows\\System32\\config');
    }

    #[Test]
    public function backslashAbsolutePathThrows(): void
    {
        $this->expectException(CmsException::class);

        $this->disk->read('\\server\\share\\file.txt');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
