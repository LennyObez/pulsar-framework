<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\UploadResult;

#[CoversClass(UploadResult::class)]
final class UploadResultTest extends TestCase
{
    #[Test]
    public function propertiesAreAccessible(): void
    {
        $result = new UploadResult(
            storagePath: '/var/uploads/abc123.pdf',
            storageName: 'abc123.pdf',
            originalName: 'report.pdf',
            mimeType: 'application/pdf',
            size: 1_048_576,
        );

        self::assertSame('/var/uploads/abc123.pdf', $result->storagePath);
        self::assertSame('abc123.pdf', $result->storageName);
        self::assertSame('report.pdf', $result->originalName);
        self::assertSame('application/pdf', $result->mimeType);
        self::assertSame(1_048_576, $result->size);
    }

    #[Test]
    public function zeroSizeUpload(): void
    {
        $result = new UploadResult(
            storagePath: '/tmp/empty',
            storageName: 'empty',
            originalName: 'empty.txt',
            mimeType: 'text/plain',
            size: 0,
        );

        self::assertSame(0, $result->size);
    }
}
