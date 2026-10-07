<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\OrderItem;

#[CoversClass(OrderItem::class)]
final class OrderItemTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $item = new OrderItem(
            id: 'oi-001',
            orderId: 'ord-001',
            productId: 'prod-001',
            variantId: 'var-001',
            quantity: 2,
            unitPrice: 1999,
            totalPrice: 3998,
            taxAmount: 756,
            discountAmount: 200,
            productSnapshot: ['name' => 'Widget', 'sku' => 'WDG-001'],
        );

        self::assertSame('oi-001', $item->id);
        self::assertSame('ord-001', $item->orderId);
        self::assertSame('prod-001', $item->productId);
        self::assertSame('var-001', $item->variantId);
        self::assertSame(2, $item->quantity);
        self::assertSame(1999, $item->unitPrice);
        self::assertSame(3998, $item->totalPrice);
        self::assertSame(756, $item->taxAmount);
        self::assertSame(200, $item->discountAmount);
        self::assertSame('Widget', $item->productSnapshot['name']);
    }

    #[Test]
    public function variantIdIsNullable(): void
    {
        $item = new OrderItem(
            id: 'oi-002',
            orderId: 'ord-001',
            productId: 'prod-002',
            variantId: null,
            quantity: 1,
            unitPrice: 999,
            totalPrice: 999,
            taxAmount: 190,
            discountAmount: 0,
            productSnapshot: [],
        );

        self::assertNull($item->variantId);
    }
}
