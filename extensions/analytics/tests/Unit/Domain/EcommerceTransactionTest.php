<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\EcommerceItem;
use Pulsar\Extension\Analytics\Domain\EcommerceTransaction;

#[CoversClass(EcommerceTransaction::class)]
final class EcommerceTransactionTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $now = new DateTimeImmutable('2026-03-15');
        $items = [
            new EcommerceItem('p-1', 'Widget', 'Electronics', 29.99, 2),
        ];

        $txn = new EcommerceTransaction(
            id: 'txn-1',
            siteId: 'site-1',
            visitorId: 'v-abc',
            sessionId: 'sess-123',
            orderId: 'ORD-001',
            revenue: 59.98,
            tax: 5.40,
            shipping: 9.99,
            currency: 'EUR',
            items: $items,
            createdAt: $now,
        );

        self::assertSame('txn-1', $txn->id);
        self::assertSame('site-1', $txn->siteId);
        self::assertSame('v-abc', $txn->visitorId);
        self::assertSame('sess-123', $txn->sessionId);
        self::assertSame('ORD-001', $txn->orderId);
        self::assertSame(59.98, $txn->revenue);
        self::assertSame(5.40, $txn->tax);
        self::assertSame(9.99, $txn->shipping);
        self::assertSame('EUR', $txn->currency);
        self::assertCount(1, $txn->items);
        self::assertSame($now, $txn->createdAt);
    }

    #[Test]
    public function defaultValues(): void
    {
        $txn = new EcommerceTransaction(
            id: 'txn-2',
            siteId: 'site-1',
            visitorId: 'v-def',
            sessionId: 'sess-456',
            orderId: 'ORD-002',
            revenue: 100.0,
        );

        self::assertSame(0.0, $txn->tax);
        self::assertSame(0.0, $txn->shipping);
        self::assertSame('USD', $txn->currency);
        self::assertSame([], $txn->items);
        self::assertInstanceOf(DateTimeImmutable::class, $txn->createdAt);
    }
}
