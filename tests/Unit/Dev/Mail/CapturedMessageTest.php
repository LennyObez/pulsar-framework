<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Mail\CapturedMessage;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Message;

#[CoversClass(CapturedMessage::class)]
final class CapturedMessageTest extends TestCase
{
    private function createMessage(
        string $from = 'sender@example.com',
        string $to = 'recipient@example.com',
        string $subject = 'Test Email',
    ): Message {
        return new Message(
            from: new Address($from),
            to: [new Address($to)],
            subject: $subject,
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
        );
    }

    #[Test]
    public function constructorAssignsAllProperties(): void
    {
        $message = $this->createMessage();
        $captured = new CapturedMessage(
            id: 'abc123',
            message: $message,
            capturedAt: 1700000000.5,
        );

        self::assertSame('abc123', $captured->id);
        self::assertSame($message, $captured->message);
        self::assertSame(1700000000.5, $captured->capturedAt);
    }

    #[Test]
    public function toSummaryReturnsCorrectStructure(): void
    {
        $message = $this->createMessage('alice@test.com', 'bob@test.com', 'Important Update');
        $captured = new CapturedMessage('msg-1', $message, 1700000000.0);

        $summary = $captured->toSummary();

        self::assertSame('msg-1', $summary['id']);
        self::assertSame('alice@test.com', $summary['from']);
        self::assertSame(['bob@test.com'], $summary['to']);
        self::assertSame('Important Update', $summary['subject']);
        self::assertTrue($summary['has_html']);
        self::assertTrue($summary['has_text']);
        self::assertSame(0, $summary['attachment_count']);
        self::assertSame(1700000000.0, $summary['captured_at']);
    }

    #[Test]
    public function toSummaryReportsMultipleRecipients(): void
    {
        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('a@test.com'), new Address('b@test.com'), new Address('c@test.com')],
            subject: 'Group email',
        );

        $captured = new CapturedMessage('msg-2', $message, 0.0);
        $summary = $captured->toSummary();

        self::assertCount(3, $summary['to']);
        self::assertSame(['a@test.com', 'b@test.com', 'c@test.com'], $summary['to']);
    }

    #[Test]
    public function toSummaryReportsNoHtmlWhenAbsent(): void
    {
        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('recipient@test.com')],
            subject: 'Plain text only',
            textBody: 'Just text',
        );

        $captured = new CapturedMessage('msg-3', $message, 0.0);
        $summary = $captured->toSummary();

        self::assertFalse($summary['has_html']);
        self::assertTrue($summary['has_text']);
    }

    #[Test]
    public function toSummaryCountsAttachments(): void
    {
        $attachment1 = new Attachment('file1.pdf', 'data1', 'application/pdf');
        $attachment2 = new Attachment('file2.txt', 'data2', 'text/plain');

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('recipient@test.com')],
            subject: 'With attachments',
            attachments: [$attachment1, $attachment2],
        );

        $captured = new CapturedMessage('msg-4', $message, 0.0);
        $summary = $captured->toSummary();

        self::assertSame(2, $summary['attachment_count']);
    }

    #[Test]
    public function toSummaryContainsExactlyEightKeys(): void
    {
        $message = $this->createMessage();
        $captured = new CapturedMessage('msg-5', $message, 0.0);
        $summary = $captured->toSummary();

        self::assertCount(8, $summary);
        self::assertArrayHasKey('id', $summary);
        self::assertArrayHasKey('from', $summary);
        self::assertArrayHasKey('to', $summary);
        self::assertArrayHasKey('subject', $summary);
        self::assertArrayHasKey('has_html', $summary);
        self::assertArrayHasKey('has_text', $summary);
        self::assertArrayHasKey('attachment_count', $summary);
        self::assertArrayHasKey('captured_at', $summary);
    }
}
