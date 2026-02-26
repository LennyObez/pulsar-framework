<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Event\MailEvent;
use Pulsar\Mail\Event\MailFailed;

#[CoversClass(MailFailed::class)]
#[CoversClass(MailEvent::class)]
final class MailFailedTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $event = new MailFailed(
            messageId: 'msg-001',
            occurredAt: 1700000000,
            reason: 'Connection refused',
            driver: 'smtp',
        );

        self::assertSame('msg-001', $event->messageId);
        self::assertSame(1700000000, $event->occurredAt);
        self::assertSame('Connection refused', $event->reason);
        self::assertSame('smtp', $event->driver);
    }

    #[Test]
    public function it_extends_mail_event(): void
    {
        $event = new MailFailed('msg-002', 1700000000, 'Timeout', 'ses');

        self::assertInstanceOf(MailEvent::class, $event);
    }
}
