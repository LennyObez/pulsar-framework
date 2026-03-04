<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\UploadedFile;

#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    #[Test]
    public function propertiesAreAccessible(): void
    {
        $file = new UploadedFile(
            originalName: 'photo.png',
            mimeType: 'image/png',
            size: 12345,
            temporaryPath: '/tmp/upload_abc123',
        );

        self::assertSame('photo.png', $file->originalName);
        self::assertSame('image/png', $file->mimeType);
        self::assertSame(12345, $file->size);
        self::assertSame('/tmp/upload_abc123', $file->temporaryPath);
    }

    #[Test]
    public function extensionReturnsFileExtension(): void
    {
        $file = new UploadedFile('document.PDF', 'application/pdf', 100, '/tmp/x');

        self::assertSame('pdf', $file->extension());
    }

    #[Test]
    public function extensionReturnsEmptyForNoExtension(): void
    {
        $file = new UploadedFile('README', 'text/plain', 100, '/tmp/x');

        self::assertSame('', $file->extension());
    }

    #[Test]
    public function safeFilenameRemovesSpecialChars(): void
    {
        $file = new UploadedFile('my file (1).png', 'image/png', 100, '/tmp/x');

        self::assertSame('my_file__1_.png', $file->safeFilename());
    }

    #[Test]
    public function safeFilenameWithoutExtension(): void
    {
        $file = new UploadedFile('README', 'text/plain', 100, '/tmp/x');

        self::assertSame('README', $file->safeFilename());
    }

    #[Test]
    #[DataProvider('extensionProvider')]
    public function extensionFromVariousNames(string $name, string $expected): void
    {
        $file = new UploadedFile($name, 'application/octet-stream', 100, '/tmp/x');

        self::assertSame($expected, $file->extension());
    }

    /** @return iterable<string, array{string, string}> */
    public static function extensionProvider(): iterable
    {
        yield 'simple' => ['image.jpg', 'jpg'];
        yield 'uppercase' => ['FILE.TXT', 'txt'];
        yield 'double dot' => ['archive.tar.gz', 'gz'];
        yield 'no extension' => ['Makefile', ''];
        yield 'dot only' => ['.gitignore', 'gitignore'];
    }
}
