<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Message;
use ReflectionClass;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    #[Test]
    public function it_constructs_with_required_fields(): void
    {
        $from = new Address('sender@test.com', 'Sender');
        $to = [new Address('user@test.com')];

        $message = new Message(
            from: $from,
            to: $to,
            subject: 'Hello World',
        );

        self::assertSame($from, $message->from);
        self::assertSame($to, $message->to);
        self::assertSame('Hello World', $message->subject);
        self::assertSame([], $message->cc);
        self::assertSame([], $message->bcc);
        self::assertNull($message->replyTo);
        self::assertNull($message->htmlBody);
        self::assertNull($message->textBody);
        self::assertSame([], $message->attachments);
        self::assertSame([], $message->headers);
        self::assertSame(3, $message->priority);
        self::assertSame([], $message->metadata);
    }

    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $from = new Address('sender@test.com');
        $to = [new Address('user@test.com')];
        $cc = [new Address('cc@test.com')];
        $bcc = [new Address('bcc@test.com')];
        $replyTo = new Address('reply@test.com');
        $attachment = new Attachment('file.txt', 'content', 'text/plain');

        $message = new Message(
            from: $from,
            to: $to,
            subject: 'Full Message',
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            htmlBody: '<p>HTML</p>',
            textBody: 'Plain text',
            attachments: [$attachment],
            headers: ['X-Test' => 'value'],
            priority: 1,
            metadata: ['key' => 'val'],
        );

        self::assertSame($cc, $message->cc);
        self::assertSame($bcc, $message->bcc);
        self::assertSame($replyTo, $message->replyTo);
        self::assertSame('<p>HTML</p>', $message->htmlBody);
        self::assertSame('Plain text', $message->textBody);
        self::assertCount(1, $message->attachments);
        self::assertSame(['X-Test' => 'value'], $message->headers);
        self::assertSame(1, $message->priority);
        self::assertSame(['key' => 'val'], $message->metadata);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new ReflectionClass(Message::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
