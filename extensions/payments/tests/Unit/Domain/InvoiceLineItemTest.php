<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\InvoiceLineItem;
use Pulsar\Extension\Payments\Domain\Money;

final class InvoiceLineItemTest extends TestCase
{
    #[Test]
    public function createCalculatesTotalCorrectly(): void
    {
        $item = InvoiceLineItem::create('Widget', 3, Money::of(1000, Currency::USD));

        self::assertSame('Widget', $item->description);
        self::assertSame(3, $item->quantity);
        self::assertSame(1000, $item->unitPrice->amount);
        self::assertSame(3000, $item->total->amount);
    }

    #[Test]
    public function createWithQuantityOne(): void
    {
        $item = InvoiceLineItem::create('Single', 1, Money::of(4999, Currency::EUR));

        self::assertSame(4999, $item->total->amount);
        self::assertSame(Currency::EUR, $item->total->currency);
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $unitPrice = Money::of(500, Currency::GBP);
        $total = Money::of(2500, Currency::GBP);

        $item = new InvoiceLineItem('Manual', 5, $unitPrice, $total);

        self::assertSame('Manual', $item->description);
        self::assertSame(5, $item->quantity);
        self::assertSame($unitPrice, $item->unitPrice);
        self::assertSame($total, $item->total);
    }
}
