<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Cart\Cart;
use Pulsar\Extension\Payments\Cart\CartItem;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

#[CoversClass(Cart::class)]
#[CoversClass(CartItem::class)]
final class CartTest extends TestCase
{
    #[Test]
    public function newCartIsEmpty(): void
    {
        $cart = Cart::create();

        self::assertSame(0, $cart->getItemCount());
        self::assertSame(0, $cart->getSubtotal()->amount);
        self::assertSame([], $cart->items);
    }

    #[Test]
    public function addItemIncreasesCount(): void
    {
        $cart = Cart::create();
        $item = new CartItem('item-1', 'prod-1', 'Widget', 2, Money::of(1500, Currency::EUR));

        $updated = $cart->addItem($item);

        self::assertSame(2, $updated->getItemCount());
        self::assertSame(3000, $updated->getSubtotal()->amount);
        self::assertCount(1, $updated->items);
    }

    #[Test]
    public function addDuplicateProductMergesQuantity(): void
    {
        $cart = Cart::create();
        $item1 = new CartItem('item-1', 'prod-1', 'Widget', 2, Money::of(1500, Currency::EUR));
        $item2 = new CartItem('item-2', 'prod-1', 'Widget', 3, Money::of(1500, Currency::EUR));

        $updated = $cart->addItem($item1)->addItem($item2);

        self::assertSame(5, $updated->getItemCount());
        self::assertSame(7500, $updated->getSubtotal()->amount);
        self::assertCount(1, $updated->items);
    }

    #[Test]
    public function removeItemDecreasesCount(): void
    {
        $cart = Cart::create();
        $item = new CartItem('item-1', 'prod-1', 'Widget', 1, Money::of(1000, Currency::EUR));

        $updated = $cart->addItem($item)->removeItem('item-1');

        self::assertSame(0, $updated->getItemCount());
        self::assertSame(0, $updated->getSubtotal()->amount);
    }

    #[Test]
    public function removeNonexistentItemReturnsSameItemList(): void
    {
        $cart = Cart::create();

        $updated = $cart->removeItem('nonexistent');

        self::assertSame(0, $updated->getItemCount());
    }

    #[Test]
    public function updateQuantityChangesItemCount(): void
    {
        $cart = Cart::create();
        $item = new CartItem('item-1', 'prod-1', 'Widget', 1, Money::of(2000, Currency::EUR));

        $updated = $cart->addItem($item)->updateQuantity('item-1', 5);

        self::assertSame(5, $updated->getItemCount());
        self::assertSame(10000, $updated->getSubtotal()->amount);
    }

    #[Test]
    public function clearRemovesAllItems(): void
    {
        $cart = Cart::create();
        $item1 = new CartItem('item-1', 'prod-1', 'Widget A', 2, Money::of(1000, Currency::EUR));
        $item2 = new CartItem('item-2', 'prod-2', 'Widget B', 1, Money::of(2000, Currency::EUR));

        $updated = $cart->addItem($item1)->addItem($item2)->clear();

        self::assertSame(0, $updated->getItemCount());
        self::assertSame(0, $updated->getSubtotal()->amount);
    }

    #[Test]
    public function cartItemLineTotalCalculatesCorrectly(): void
    {
        $item = new CartItem('item-1', 'prod-1', 'Gadget', 3, Money::of(2500, Currency::EUR));

        $total = $item->lineTotal();

        self::assertSame(7500, $total->amount);
        self::assertSame(Currency::EUR, $total->currency);
    }

    #[Test]
    public function cartItemWithQuantityReturnsNewInstance(): void
    {
        $item = new CartItem('item-1', 'prod-1', 'Gadget', 1, Money::of(2500, Currency::EUR));

        $updated = $item->withQuantity(5);

        self::assertSame(1, $item->quantity);
        self::assertSame(5, $updated->quantity);
        self::assertSame(12500, $updated->lineTotal()->amount);
    }

    #[Test]
    public function multipleItemsSubtotalSumsCorrectly(): void
    {
        $cart = Cart::create();

        $updated = $cart
            ->addItem(new CartItem('i1', 'p1', 'A', 2, Money::of(1000, Currency::EUR)))
            ->addItem(new CartItem('i2', 'p2', 'B', 1, Money::of(3500, Currency::EUR)))
            ->addItem(new CartItem('i3', 'p3', 'C', 3, Money::of(750, Currency::EUR)));

        self::assertSame(6, $updated->getItemCount());
        self::assertSame(7750, $updated->getSubtotal()->amount);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function cartItemTotalProvider(): iterable
    {
        yield 'single item at 10.00' => [1, 1000, 1000];
        yield 'five items at 5.50' => [5, 550, 2750];
        yield 'large quantity' => [100, 99, 9900];
    }

    #[Test]
    #[DataProvider('cartItemTotalProvider')]
    public function cartItemLineTotalWithDataProvider(int $qty, int $price, int $expected): void
    {
        /** @var int<1, max> $qty */
        $item = new CartItem('id', 'pid', 'Name', $qty, Money::of($price, Currency::EUR));

        self::assertSame($expected, $item->lineTotal()->amount);
    }

    #[Test]
    public function guestCartHasNoUserId(): void
    {
        $cart = Cart::create();

        self::assertNull($cart->userId);
    }

    #[Test]
    public function assignUserSetsUserId(): void
    {
        $cart = Cart::create();

        $assigned = $cart->assignUser('user-42');

        self::assertNull($cart->userId);
        self::assertSame('user-42', $assigned->userId);
    }

    #[Test]
    public function couponCanBeAppliedAndRemoved(): void
    {
        $cart = Cart::create();

        $withCoupon = $cart->withCoupon('SAVE10');
        self::assertSame('SAVE10', $withCoupon->couponCode);

        $withoutCoupon = $withCoupon->withoutCoupon();
        self::assertNull($withoutCoupon->couponCode);
    }

    #[Test]
    public function cartItemSerializesAndDeserializes(): void
    {
        $original = new CartItem('item-1', 'prod-1', 'Widget', 3, Money::of(1500, Currency::EUR), ['color' => 'red']);

        /** @var array{id: non-empty-string, productId: non-empty-string, productName: string, quantity: int<1, max>, unitPriceAmount: int, currency: string, metadata: array<string, mixed>} $array */
        $array = $original->toArray();
        $restored = CartItem::fromArray($array);

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->productId, $restored->productId);
        self::assertSame($original->quantity, $restored->quantity);
        self::assertSame($original->unitPrice->amount, $restored->unitPrice->amount);
        self::assertSame($original->metadata, $restored->metadata);
    }

    #[Test]
    public function mergeCartsCombinesItems(): void
    {
        $cart1 = Cart::create()
            ->addItem(new CartItem('i1', 'p1', 'A', 2, Money::of(1000, Currency::EUR)));

        $cart2 = Cart::create()
            ->addItem(new CartItem('i2', 'p2', 'B', 1, Money::of(2000, Currency::EUR)));

        $merged = $cart1->merge($cart2);

        self::assertSame(3, $merged->getItemCount());
        self::assertCount(2, $merged->items);
    }
}
