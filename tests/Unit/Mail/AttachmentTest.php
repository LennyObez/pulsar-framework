<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Attachment;
use ReflectionClass;

#[CoversClass(Attachment::class)]
final class AttachmentTest extends TestCase
{
    #[Test]
    public function it_constructs_regular_attachment(): void
    {
        $attachment = new Attachment(
            filename: 'report.pdf',
            content: 'binary-content',
            mimeType: 'application/pdf',
        );

        self::assertSame('report.pdf', $attachment->filename);
        self::assertSame('binary-content', $attachment->content);
        self::assertSame('application/pdf', $attachment->mimeType);
        self::assertFalse($attachment->inline);
        self::assertNull($attachment->cid);
    }

    #[Test]
    public function it_constructs_inline_attachment(): void
    {
        $attachment = new Attachment(
            filename: 'logo.png',
            content: 'image-data',
            mimeType: 'image/png',
            inline: true,
            cid: 'logo',
        );

        self::assertSame('logo.png', $attachment->filename);
        self::assertSame('image-data', $attachment->content);
        self::assertSame('image/png', $attachment->mimeType);
        self::assertTrue($attachment->inline);
        self::assertSame('logo', $attachment->cid);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new ReflectionClass(Attachment::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
