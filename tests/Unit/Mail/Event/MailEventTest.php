<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Event\MailEvent;
use Pulsar\Mail\Event\MailSent;

#[CoversClass(MailEvent::class)]
final class MailEventTest extends TestCase
{
    #[Test]
    public function storesMessageIdAndTimestamp(): void
    {
        $event = new MailSent('msg-001', 1709856000, recipientCount: 1, driver: 'smtp');

        self::assertSame('msg-001', $event->messageId);
        self::assertSame(1709856000, $event->occurredAt);
    }
}
