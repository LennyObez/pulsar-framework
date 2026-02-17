<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Domain\DeliveryReceipt;

final class DeliveryReceiptTest extends TestCase
{
    #[Test]
    public function toArrayContainsAllFieldsWhenDelivered(): void
    {
        $sentAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $deliveredAt = new DateTimeImmutable('2026-01-15T10:01:00+00:00');

        $receipt = new DeliveryReceipt(
            receiptId: 'rcpt_001',
            messageId: 'msg_001',
            sender: 'alice@example.com',
            recipient: 'bob@example.com',
            contentHash: 'abc123hash',
            hashAlgorithm: 'sha256',
            sentAt: $sentAt,
            deliveredAt: $deliveredAt,
            delivered: true,
        );

        $array = $receipt->toArray();

        self::assertSame('rcpt_001', $array['receipt_id']);
        self::assertSame('msg_001', $array['message_id']);
        self::assertSame('alice@example.com', $array['sender']);
        self::assertSame('bob@example.com', $array['recipient']);
        self::assertTrue($array['delivered']);
        self::assertNotNull($array['delivered_at']);
    }

    #[Test]
    public function toArrayHandlesUndeliveredMessage(): void
    {
        $receipt = new DeliveryReceipt(
            receiptId: 'rcpt_002',
            messageId: 'msg_002',
            sender: 'alice@example.com',
            recipient: 'bob@example.com',
            contentHash: 'abc123hash',
            hashAlgorithm: 'sha256',
            sentAt: new DateTimeImmutable(),
            deliveredAt: null,
            delivered: false,
        );

        $array = $receipt->toArray();

        self::assertFalse($array['delivered']);
        self::assertNull($array['delivered_at']);
    }
}
