<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Event\MailEvent;
use Pulsar\Mail\Event\MailSent;

#[CoversClass(MailSent::class)]
#[CoversClass(MailEvent::class)]
final class MailSentTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $event = new MailSent(
            messageId: 'msg-001',
            occurredAt: 1700000000,
            recipientCount: 3,
            driver: 'smtp',
        );

        self::assertSame('msg-001', $event->messageId);
        self::assertSame(1700000000, $event->occurredAt);
        self::assertSame(3, $event->recipientCount);
        self::assertSame('smtp', $event->driver);
    }

    #[Test]
    public function it_extends_mail_event(): void
    {
        $event = new MailSent('msg-002', 1700000000, 1, 'ses');

        self::assertInstanceOf(MailEvent::class, $event);
    }
}
