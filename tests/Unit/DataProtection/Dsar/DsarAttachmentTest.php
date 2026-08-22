<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection\Dsar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\DataProtection\Dsar\DsarAttachment;

#[CoversClass(DsarAttachment::class)]
final class DsarAttachmentTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $attachment = new DsarAttachment(
            filename: 'profile-photo.jpg',
            content: 'binary-image-data',
            mimeType: 'image/jpeg',
        );

        self::assertSame('profile-photo.jpg', $attachment->filename);
        self::assertSame('binary-image-data', $attachment->content);
        self::assertSame('image/jpeg', $attachment->mimeType);
    }

    #[Test]
    public function defaultMimeTypeIsOctetStream(): void
    {
        $attachment = new DsarAttachment(
            filename: 'data.bin',
            content: "\x00\x01\x02",
        );

        self::assertSame('application/octet-stream', $attachment->mimeType);
    }

    #[Test]
    public function emptyContentIsAllowed(): void
    {
        $attachment = new DsarAttachment(
            filename: 'empty.txt',
            content: '',
            mimeType: 'text/plain',
        );

        self::assertSame('', $attachment->content);
        self::assertSame('text/plain', $attachment->mimeType);
    }

    #[Test]
    public function binaryContentIsPreserved(): void
    {
        $binary = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 10);
        $attachment = new DsarAttachment(
            filename: 'test.png',
            content: $binary,
            mimeType: 'image/png',
        );

        self::assertSame($binary, $attachment->content);
    }

    #[Test]
    public function variousMimeTypes(): void
    {
        $pdf = new DsarAttachment('document.pdf', 'pdf-data', 'application/pdf');
        self::assertSame('application/pdf', $pdf->mimeType);

        $csv = new DsarAttachment('export.csv', 'a,b,c', 'text/csv');
        self::assertSame('text/csv', $csv->mimeType);

        $json = new DsarAttachment('data.json', '{"key":"val"}', 'application/json');
        self::assertSame('application/json', $json->mimeType);
    }
}
