<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\MailEncryptionPolicy;
use Pulsar\Mail\Event\MailEncryptionFallbackEvent;
use Pulsar\Mail\Event\MailEvent;

#[CoversClass(MailEncryptionFallbackEvent::class)]
#[CoversClass(MailEvent::class)]
final class MailEncryptionFallbackEventTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $event = new MailEncryptionFallbackEvent(
            messageId: 'msg-abc12345-def6-7890-abcd-ef1234567890',
            occurredAt: 1709827200,
            recipientEmail: 'patient@hospital.org',
            reason: 'No public key found for recipient',
            policy: MailEncryptionPolicy::Prefer,
        );

        self::assertSame('msg-abc12345-def6-7890-abcd-ef1234567890', $event->messageId);
        self::assertSame(1709827200, $event->occurredAt);
        self::assertSame('patient@hospital.org', $event->recipientEmail);
        self::assertSame('No public key found for recipient', $event->reason);
        self::assertSame(MailEncryptionPolicy::Prefer, $event->policy);
    }

    #[Test]
    public function extendsMailEvent(): void
    {
        $event = new MailEncryptionFallbackEvent(
            messageId: 'msg-00000000-0000-0000-0000-000000000000',
            occurredAt: 1709827200,
            recipientEmail: 'admin@bank.com',
            reason: 'Certificate expired',
            policy: MailEncryptionPolicy::Prefer,
        );

        self::assertInstanceOf(MailEvent::class, $event);
    }
}
