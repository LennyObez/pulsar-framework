<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Internal\Delivery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Eidas\Exception\EidasException;
use Pulsar\Extension\Eidas\Internal\Delivery\InMemoryDeliveryService;

final class InMemoryDeliveryServiceTest extends TestCase
{
    #[Test]
    public function sendCreatesUndeliveredReceipt(): void
    {
        $service = new InMemoryDeliveryService();

        $receipt = $service->send('alice@example.com', 'bob@example.com', 'Hello Bob');

        self::assertNotEmpty($receipt->receiptId);
        self::assertNotEmpty($receipt->messageId);
        self::assertSame('alice@example.com', $receipt->sender);
        self::assertSame('bob@example.com', $receipt->recipient);
        self::assertFalse($receipt->delivered);
        self::assertNull($receipt->deliveredAt);
        self::assertSame('sha256', $receipt->hashAlgorithm);
        self::assertNotEmpty($receipt->contentHash);
    }

    #[Test]
    public function confirmDeliveryMarksAsDelivered(): void
    {
        $service = new InMemoryDeliveryService();
        $receipt = $service->send('alice@example.com', 'bob@example.com', 'Hello');

        $confirmed = $service->confirmDelivery($receipt->receiptId);

        self::assertTrue($confirmed->delivered);
        self::assertNotNull($confirmed->deliveredAt);
        self::assertSame($receipt->receiptId, $confirmed->receiptId);
        self::assertSame($receipt->messageId, $confirmed->messageId);
    }

    #[Test]
    public function confirmDeliveryThrowsForUnknownReceipt(): void
    {
        $service = new InMemoryDeliveryService();

        $this->expectException(EidasException::class);
        $this->expectExceptionMessageIsOrContains('Receipt not found');

        $service->confirmDelivery('nonexistent');
    }

    #[Test]
    public function getReceiptReturnsStoredReceipt(): void
    {
        $service = new InMemoryDeliveryService();
        $receipt = $service->send('alice@example.com', 'bob@example.com', 'Content');

        $found = $service->getReceipt($receipt->receiptId);

        self::assertNotNull($found);
        self::assertSame($receipt->receiptId, $found->receiptId);
    }

    #[Test]
    public function getReceiptReturnsNullForUnknown(): void
    {
        $service = new InMemoryDeliveryService();

        self::assertNull($service->getReceipt('nonexistent'));
    }

    #[Test]
    public function getReceiptReflectsConfirmation(): void
    {
        $service = new InMemoryDeliveryService();
        $receipt = $service->send('a', 'b', 'content');
        $service->confirmDelivery($receipt->receiptId);

        $found = $service->getReceipt($receipt->receiptId);

        self::assertNotNull($found);
        self::assertTrue($found->delivered);
    }

    #[Test]
    public function contentHashDiffersForDifferentContent(): void
    {
        $service = new InMemoryDeliveryService();
        $r1 = $service->send('a', 'b', 'content1');
        $r2 = $service->send('a', 'b', 'content2');

        self::assertNotSame($r1->contentHash, $r2->contentHash);
    }
}
