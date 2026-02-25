<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Upload\UploadResult;

final class UploadResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new UploadResult(
            storagePath: '/uploads/abc.pdf',
            storageName: 'abc.pdf',
            originalName: 'document.pdf',
            mimeType: 'application/pdf',
            size: 12345,
        );

        self::assertSame('/uploads/abc.pdf', $result->storagePath);
        self::assertSame('abc.pdf', $result->storageName);
        self::assertSame('document.pdf', $result->originalName);
        self::assertSame('application/pdf', $result->mimeType);
        self::assertSame(12345, $result->size);
    }
}
