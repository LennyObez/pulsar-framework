<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Envelope;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $from = new Address('sender@test.com', 'Sender');
        $to1 = new Address('user@test.com', 'User');
        $cc1 = new Address('cc@test.com', 'CC');
        $bcc1 = new Address('bcc@test.com', 'BCC');
        $replyTo = new Address('reply@test.com', 'Reply');

        $envelope = new Envelope(
            subject: 'Test Subject',
            from: $from,
            to: [$to1],
            cc: [$cc1],
            bcc: [$bcc1],
            replyTo: $replyTo,
        );

        self::assertSame('Test Subject', $envelope->subject);
        self::assertSame($from, $envelope->from);
        self::assertCount(1, $envelope->to);
        self::assertCount(1, $envelope->cc);
        self::assertCount(1, $envelope->bcc);
        self::assertSame($replyTo, $envelope->replyTo);
    }

    #[Test]
    public function defaultsForOptionalFields(): void
    {
        $envelope = new Envelope(subject: 'Minimal');

        self::assertSame('Minimal', $envelope->subject);
        self::assertNull($envelope->from);
        self::assertSame([], $envelope->to);
        self::assertSame([], $envelope->cc);
        self::assertSame([], $envelope->bcc);
        self::assertNull($envelope->replyTo);
    }

    #[Test]
    public function multipleRecipients(): void
    {
        $envelope = new Envelope(
            subject: 'Broadcast',
            to: [
                new Address('a@test.com'),
                new Address('b@test.com'),
                new Address('c@test.com'),
            ],
        );

        self::assertCount(3, $envelope->to);
    }
}
