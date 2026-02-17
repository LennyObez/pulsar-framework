<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\FileUpload;

#[CoversClass(FileUpload::class)]
final class FileUploadTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $upload = new FileUpload();

        self::assertSame(10_485_760, $upload->maxSize);
        self::assertSame([], $upload->accept);
        self::assertSame('local', $upload->disk);
        self::assertFalse($upload->multiple);
    }

    #[Test]
    public function customValues(): void
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
    public function validateFilePassesForValidFile(): void
    {
        $upload = new FileUpload(maxSize: 10_000_000, accept: ['image/png']);

        self::assertSame([], $upload->validateFile('image/png', 5_000_000, 'photo.png'));
    }

    #[Test]
    public function validateFileRejectsOversizedFile(): void
    {
        $upload = new FileUpload(maxSize: 1_000_000);
        $errors = $upload->validateFile('image/png', 2_000_000, 'big.png');

        self::assertCount(1, $errors);
        self::assertStringContainsString('exceeds the maximum size', $errors[0]);
    }

    #[Test]
    public function validateFileRejectsDisallowedMimeType(): void
    {
        $upload = new FileUpload(accept: ['image/png', 'image/jpeg']);
        $errors = $upload->validateFile('application/pdf', 1000, 'file.pdf');

        self::assertCount(1, $errors);
        self::assertStringContainsString('unsupported type', $errors[0]);
    }

    #[Test]
    public function validateFileAllowsAnyMimeWhenAcceptEmpty(): void
    {
        $upload = new FileUpload(accept: []);

        self::assertSame([], $upload->validateFile('application/pdf', 1000, 'file.pdf'));
    }

    #[Test]
    public function validateFileReturnsMultipleErrors(): void
    {
        $upload = new FileUpload(maxSize: 100, accept: ['image/png']);
        $errors = $upload->validateFile('application/pdf', 200, 'bad.pdf');

        self::assertCount(2, $errors);
    }
}
