<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Message;
use Pulsar\Mail\Transport\ArrayTransport;

#[CoversClass(ArrayTransport::class)]
final class ArrayTransportTest extends TestCase
{
    #[Test]
    public function it_stores_sent_messages(): void
    {
        $transport = new ArrayTransport();

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $transport->send($message);

        self::assertCount(1, $transport->sent());
        self::assertSame($message, $transport->sent()[0]);
    }

    #[Test]
    public function it_returns_message_id(): void
    {
        $transport = new ArrayTransport();

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $messageId = $transport->send($message);

        self::assertNotEmpty($messageId);
        self::assertStringStartsWith('<array-', $messageId);
        self::assertStringEndsWith('@localhost>', $messageId);
    }

    #[Test]
    public function it_stores_multiple_messages(): void
    {
        $transport = new ArrayTransport();

        for ($i = 0; $i < 3; $i++) {
            $transport->send(new Message(
                from: new Address('sender@test.com'),
                to: [new Address('user@test.com')],
                subject: "Message {$i}",
            ));
        }

        self::assertCount(3, $transport->sent());
    }

    #[Test]
    public function it_flushes_stored_messages(): void
    {
        $transport = new ArrayTransport();

        $transport->send(new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        ));

        self::assertCount(1, $transport->sent());

        $transport->flush();

        self::assertCount(0, $transport->sent());
    }

    #[Test]
    public function it_reports_array_name(): void
    {
        $transport = new ArrayTransport();

        self::assertSame('array', $transport->name());
    }

    #[Test]
    public function it_starts_empty(): void
    {
        $transport = new ArrayTransport();

        self::assertSame([], $transport->sent());
    }

    #[Test]
    public function it_generates_unique_message_ids(): void
    {
        $transport = new ArrayTransport();

        $message = new Message(
            from: new Address('sender@test.com'),
            to: [new Address('user@test.com')],
            subject: 'Test',
        );

        $id1 = $transport->send($message);
        $id2 = $transport->send($message);

        self::assertNotSame($id1, $id2);
    }
}
