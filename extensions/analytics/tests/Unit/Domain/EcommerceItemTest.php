<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\EcommerceItem;

#[CoversClass(EcommerceItem::class)]
final class EcommerceItemTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $item = new EcommerceItem(
            productId: 'prod-1',
            name: 'Premium Widget',
            category: 'Gadgets',
            price: 49.99,
            quantity: 3,
            variant: 'Blue',
        );

        self::assertSame('prod-1', $item->productId);
        self::assertSame('Premium Widget', $item->name);
        self::assertSame('Gadgets', $item->category);
        self::assertSame(49.99, $item->price);
        self::assertSame(3, $item->quantity);
        self::assertSame('Blue', $item->variant);
    }

    #[Test]
    public function defaultValues(): void
    {
        $item = new EcommerceItem(
            productId: 'prod-2',
            name: 'Basic Item',
        );

        self::assertSame('', $item->category);
        self::assertSame(0.0, $item->price);
        self::assertSame(1, $item->quantity);
        self::assertSame('', $item->variant);
    }
}
