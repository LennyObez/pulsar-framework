<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\DigitalSignature;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\DigitalSignature\DeliveryReceipt;

#[CoversClass(DeliveryReceipt::class)]
final class DeliveryReceiptTest extends TestCase
{
    #[Test]
    public function constructUndeliveredReceipt(): void
    {
        $sentAt = new DateTimeImmutable('2026-03-14T10:00:00+00:00');

        $receipt = new DeliveryReceipt(
            messageId: 'msg-001',
            sender: 'alice@example.com',
            recipient: 'bob@example.com',
            sentAt: $sentAt,
            contentHash: 'abc123',
        );

        self::assertSame('msg-001', $receipt->messageId);
        self::assertSame('alice@example.com', $receipt->sender);
        self::assertSame('bob@example.com', $receipt->recipient);
        self::assertSame($sentAt, $receipt->sentAt);
        self::assertNull($receipt->deliveredAt);
        self::assertFalse($receipt->isDelivered());
        self::assertSame('abc123', $receipt->contentHash);
        self::assertSame('sha256', $receipt->hashAlgorithm);
        self::assertFalse($receipt->nonRepudiation);
    }

    #[Test]
    public function constructDeliveredReceipt(): void
    {
        $sentAt = new DateTimeImmutable('2026-03-14T10:00:00+00:00');
        $deliveredAt = new DateTimeImmutable('2026-03-14T10:05:00+00:00');

        $receipt = new DeliveryReceipt(
            messageId: 'msg-002',
            sender: 'alice@example.com',
            recipient: 'bob@example.com',
            sentAt: $sentAt,
            deliveredAt: $deliveredAt,
            contentHash: 'def456',
            hashAlgorithm: 'sha512',
            nonRepudiation: true,
        );

        self::assertTrue($receipt->isDelivered());
        self::assertSame($deliveredAt, $receipt->deliveredAt);
        self::assertSame('sha512', $receipt->hashAlgorithm);
        self::assertTrue($receipt->nonRepudiation);
    }

    #[Test]
    public function isDeliveredReturnsFalseWhenDeliveredAtIsNull(): void
    {
        $receipt = new DeliveryReceipt(
            messageId: 'msg-003',
            sender: 'sender@example.com',
            recipient: 'recipient@example.com',
            sentAt: new DateTimeImmutable(),
        );

        self::assertFalse($receipt->isDelivered());
    }

    #[Test]
    public function defaultHashAlgorithmIsSha256(): void
    {
        $receipt = new DeliveryReceipt(
            messageId: 'msg-004',
            sender: 'a@b.com',
            recipient: 'c@d.com',
            sentAt: new DateTimeImmutable(),
        );

        self::assertSame('sha256', $receipt->hashAlgorithm);
    }
}
