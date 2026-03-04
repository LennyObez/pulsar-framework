<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\FileUpload;
use Pulsar\Live\UploadedFile;

/**
 * Edge case tests for FileUpload and UploadedFile.
 */
#[CoversClass(FileUpload::class)]
#[CoversClass(UploadedFile::class)]
final class FileUploadEdgeTest extends TestCase
{
    // --- FileUpload ---

    #[Test]
    public function fileUploadDefaults(): void
    {
        $upload = new FileUpload();

        self::assertSame(10_485_760, $upload->maxSize);
        self::assertSame([], $upload->accept);
        self::assertSame('local', $upload->disk);
        self::assertFalse($upload->multiple);
    }

    #[Test]
    public function fileUploadCustomValues(): void
    {
        $upload = new FileUpload(
            maxSize: 5_000_000,
            accept: ['image/png', 'image/jpeg'],
            disk: 's3',
            multiple: true,
        );

        self::assertSame(5_000_000, $upload->maxSize);
        self::assertSame(['image/png', 'image/jpeg'], $upload->accept);
        self::assertSame('s3', $upload->disk);
        self::assertTrue($upload->multiple);
    }

    #[Test]
    public function validateFileReturnsEmptyForValidFile(): void
    {
        $upload = new FileUpload(maxSize: 10_000_000, accept: ['image/png']);

        $errors = $upload->validateFile('image/png', 5_000_000, 'photo.png');

        self::assertSame([], $errors);
    }

    #[Test]
    public function validateFileReturnsErrorForOversizedFile(): void
    {
        $upload = new FileUpload(maxSize: 1_000_000);

        $errors = $upload->validateFile('image/png', 2_000_000, 'big.png');

        self::assertCount(1, $errors);
        self::assertStringContainsString('exceeds', $errors[0]);
        self::assertStringContainsString('big.png', $errors[0]);
    }

    #[Test]
    public function validateFileReturnsErrorForDisallowedMimeType(): void
    {
        $upload = new FileUpload(accept: ['image/png', 'image/jpeg']);

        $errors = $upload->validateFile('application/pdf', 100, 'doc.pdf');

        self::assertCount(1, $errors);
        self::assertStringContainsString('unsupported type', $errors[0]);
        self::assertStringContainsString('doc.pdf', $errors[0]);
    }

    #[Test]
    public function validateFileReturnsMultipleErrors(): void
    {
        $upload = new FileUpload(maxSize: 1000, accept: ['image/png']);

        $errors = $upload->validateFile('text/plain', 5000, 'bad.txt');

        self::assertCount(2, $errors);
    }

    #[Test]
    public function validateFileAcceptsAnyTypeWhenAcceptIsEmpty(): void
    {
        $upload = new FileUpload(accept: []);

        $errors = $upload->validateFile('application/octet-stream', 100, 'file.bin');

        self::assertSame([], $errors);
    }

    // --- UploadedFile ---

    #[Test]
    public function uploadedFileProperties(): void
    {
        $file = new UploadedFile('photo.jpg', 'image/jpeg', 1024, '/tmp/abc123');

        self::assertSame('photo.jpg', $file->originalName);
        self::assertSame('image/jpeg', $file->mimeType);
        self::assertSame(1024, $file->size);
        self::assertSame('/tmp/abc123', $file->temporaryPath);
    }

    #[Test]
    public function extensionReturnsLowercaseExtension(): void
    {
        $file = new UploadedFile('Photo.JPG', 'image/jpeg', 100, '/tmp/x');

        self::assertSame('jpg', $file->extension());
    }

    #[Test]
    public function extensionReturnsEmptyForNoExtension(): void
    {
        $file = new UploadedFile('README', 'text/plain', 100, '/tmp/x');

        self::assertSame('', $file->extension());
    }

    #[Test]
    public function safeFilenameRemovesSpecialCharacters(): void
    {
        $file = new UploadedFile('my file (1).test.pdf', 'application/pdf', 100, '/tmp/x');

        $safe = $file->safeFilename();

        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+\.pdf$/', $safe);
    }

    #[Test]
    public function safeFilenameWithoutExtension(): void
    {
        $file = new UploadedFile('README', 'text/plain', 100, '/tmp/x');

        $safe = $file->safeFilename();

        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $safe);
    }

    #[Test]
    public function existsReturnsFalseForNonexistentPath(): void
    {
        $file = new UploadedFile('file.txt', 'text/plain', 100, '/nonexistent/path/file.txt');

        self::assertFalse($file->exists());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function extensionProvider(): array
    {
        return [
            'jpg' => ['image.jpg', 'jpg'],
            'png uppercase' => ['LOGO.PNG', 'png'],
            'multi dot' => ['archive.tar.gz', 'gz'],
            'no extension' => ['Makefile', ''],
            'hidden file' => ['.gitignore', 'gitignore'],
        ];
    }

    #[Test]
    #[DataProvider('extensionProvider')]
    public function extensionExtractsCorrectly(string $filename, string $expected): void
    {
        $file = new UploadedFile($filename, 'application/octet-stream', 0, '/tmp/x');

        self::assertSame($expected, $file->extension());
    }
}
