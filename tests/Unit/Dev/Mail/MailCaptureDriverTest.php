<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Mail\CapturedMessage;
use Pulsar\Dev\Mail\MailCaptureDriver;
use Pulsar\Mail\Address;
use Pulsar\Mail\Message;

#[CoversClass(MailCaptureDriver::class)]
final class MailCaptureDriverTest extends TestCase
{
    private function createMessage(string $subject = 'Test'): Message
    {
        return new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: $subject,
            htmlBody: '<p>Hello</p>',
        );
    }

    #[Test]
    public function nameReturnsCaptureTransportName(): void
    {
        $driver = new MailCaptureDriver();
        self::assertSame('capture', $driver->name());
    }

    #[Test]
    public function sendCapturesMessageAndReturnsId(): void
    {
        $driver = new MailCaptureDriver();
        $message = $this->createMessage();

        $messageId = $driver->send($message);

        self::assertStringStartsWith('<capture-', $messageId);
        self::assertStringEndsWith('@localhost>', $messageId);
        self::assertSame(1, $driver->count());
    }

    #[Test]
    public function sendCapturesMultipleMessages(): void
    {
        $driver = new MailCaptureDriver();

        $driver->send($this->createMessage('First'));
        $driver->send($this->createMessage('Second'));
        $driver->send($this->createMessage('Third'));

        self::assertSame(3, $driver->count());
    }

    #[Test]
    public function allReturnsMessagesNewestFirst(): void
    {
        $driver = new MailCaptureDriver();

        $driver->send($this->createMessage('First'));
        $driver->send($this->createMessage('Second'));
        $driver->send($this->createMessage('Third'));

        $all = $driver->all();

        self::assertCount(3, $all);
        self::assertSame('Third', $all[0]->message->subject);
        self::assertSame('Second', $all[1]->message->subject);
        self::assertSame('First', $all[2]->message->subject);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenNoMessages(): void
    {
        $driver = new MailCaptureDriver();
        self::assertSame([], $driver->all());
    }

    #[Test]
    public function findReturnsMessageById(): void
    {
        $driver = new MailCaptureDriver();
        $driver->send($this->createMessage('Target'));

        $all = $driver->all();
        $id = $all[0]->id;

        $found = $driver->find($id);

        self::assertInstanceOf(CapturedMessage::class, $found);
        self::assertSame('Target', $found->message->subject);
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        $driver = new MailCaptureDriver();
        $driver->send($this->createMessage());

        self::assertNull($driver->find('nonexistent-id'));
    }

    #[Test]
    public function findReturnsNullWhenEmpty(): void
    {
        $driver = new MailCaptureDriver();
        self::assertNull($driver->find('any-id'));
    }

    #[Test]
    public function countReflectsNumberOfCapturedMessages(): void
    {
        $driver = new MailCaptureDriver();
        self::assertSame(0, $driver->count());

        $driver->send($this->createMessage());
        self::assertSame(1, $driver->count());

        $driver->send($this->createMessage());
        self::assertSame(2, $driver->count());
    }

    #[Test]
    public function flushClearsAllCapturedMessages(): void
    {
        $driver = new MailCaptureDriver();
        $driver->send($this->createMessage());
        $driver->send($this->createMessage());

        self::assertSame(2, $driver->count());

        $driver->flush();

        self::assertSame(0, $driver->count());
        self::assertSame([], $driver->all());
    }

    #[Test]
    public function latestReturnsLastCapturedMessage(): void
    {
        $driver = new MailCaptureDriver();
        $driver->send($this->createMessage('First'));
        $driver->send($this->createMessage('Last'));

        $latest = $driver->latest();

        self::assertInstanceOf(CapturedMessage::class, $latest);
        self::assertSame('Last', $latest->message->subject);
    }

    #[Test]
    public function latestReturnsNullWhenEmpty(): void
    {
        $driver = new MailCaptureDriver();
        self::assertNull($driver->latest());
    }

    #[Test]
    public function eachCapturedMessageHasUniqueId(): void
    {
        $driver = new MailCaptureDriver();

        $driver->send($this->createMessage());
        $driver->send($this->createMessage());
        $driver->send($this->createMessage());

        $all = $driver->all();
        $ids = array_map(static fn(CapturedMessage $m): string => $m->id, $all);

        self::assertCount(3, array_unique($ids));
    }

    #[Test]
    public function capturedMessagePreservesOriginalMessage(): void
    {
        $driver = new MailCaptureDriver();

        $original = new Message(
            from: new Address('test@example.com', 'Test Sender'),
            to: [new Address('recipient@example.com')],
            subject: 'Preserved Message',
            htmlBody: '<h1>Hello</h1>',
            textBody: 'Hello',
            headers: ['X-Custom' => 'value'],
        );

        $driver->send($original);

        $captured = $driver->latest();
        self::assertNotNull($captured);
        self::assertSame('test@example.com', $captured->message->from->email);
        self::assertSame('Preserved Message', $captured->message->subject);
        self::assertSame('<h1>Hello</h1>', $captured->message->htmlBody);
        self::assertSame('Hello', $captured->message->textBody);
        self::assertSame(['X-Custom' => 'value'], $captured->message->headers);
    }

    #[Test]
    public function capturedMessageHasTimestamp(): void
    {
        $before = microtime(true);

        $driver = new MailCaptureDriver();
        $driver->send($this->createMessage());

        $after = microtime(true);

        $captured = $driver->latest();
        self::assertNotNull($captured);
        self::assertGreaterThanOrEqual($before, $captured->capturedAt);
        self::assertLessThanOrEqual($after, $captured->capturedAt);
    }
}
