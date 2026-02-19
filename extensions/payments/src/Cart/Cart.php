<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Cart;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function random_bytes;

/**
 * Immutable shopping cart aggregate.
 *
 * The cart holds line items for a user (or guest session) and provides
 * mutation methods that return new instances via PHP 8.5 clone-with.
 * Monetary values are tracked in minor currency units through {@see Money}.
 *
 * Guest carts use a null userId and are identified by session ID until
 * the user authenticates, at which point the guest cart is merged.
 */
#[Api(since: '1.0.0')]
final readonly class Cart
{
    /**
     * @param non-empty-string $id Cart identifier
     * @param string|null $userId Owner user ID, null for guest carts
     * @param list<CartItem> $items Line items in the cart
     * @param Currency $currency Cart currency (all items must match)
     * @param string|null $couponCode Applied coupon code, if any
     * @param DateTimeImmutable $createdAt When the cart was first created
     * @param DateTimeImmutable $updatedAt When the cart was last modified
     */
    public function __construct(
        public string $id,
        public ?string $userId,
        public array $items,
        public Currency $currency,
        public ?string $couponCode,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new empty cart.
     */
    #[NoDiscard]
    public static function create(?string $userId = null, Currency $currency = Currency::EUR): self
    {
        $now = new DateTimeImmutable();

        return new self(
            id: bin2hex(random_bytes(16)),
            userId: $userId,
            items: [],
            currency: $currency,
            couponCode: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Add an item to the cart.
     *
     * If an item with the same productId already exists, its quantity is
     * incremented instead of creating a duplicate line.
     */
    #[NoDiscard]
    public function addItem(CartItem $item): static
    {
        $existingIndex = $this->findItemIndexByProductId($item->productId);

        if ($existingIndex !== null) {
            $existing = $this->items[$existingIndex];
            $updated = $existing->withQuantity($existing->quantity + $item->quantity);

            $items = $this->items;
            $items[$existingIndex] = $updated;

            return clone($this, [
                'items' => array_values($items),
                'updatedAt' => new DateTimeImmutable(),
            ]);
        }

        return clone($this, [
            'items' => [...$this->items, $item],
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Remove an item by its cart-line ID.
     *
     * @param non-empty-string $itemId
     */
    #[NoDiscard]
    public function removeItem(string $itemId): static
    {
        $filtered = array_values(array_filter(
            $this->items,
            static fn(CartItem $i): bool => $i->id !== $itemId,
        ));

        return clone($this, [
            'items' => $filtered,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Update the quantity of a specific cart item.
     *
     * @param non-empty-string $itemId
     * @param int<1, max> $quantity
     */
    #[NoDiscard]
    public function updateQuantity(string $itemId, int $quantity): static
    {
        $items = array_map(
            static fn(CartItem $i): CartItem => $i->id === $itemId
                ? $i->withQuantity($quantity)
                : $i,
            $this->items,
        );

        return clone($this, [
            'items' => $items,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Remove all items from the cart.
     */
    #[NoDiscard]
    public function clear(): static
    {
        return clone($this, [
            'items' => [],
            'couponCode' => null,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Apply a coupon code to the cart.
     */
    #[NoDiscard]
    public function withCoupon(string $couponCode): static
    {
        return clone($this, [
            'couponCode' => $couponCode,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Remove any applied coupon.
     */
    #[NoDiscard]
    public function withoutCoupon(): static
    {
        return clone($this, [
            'couponCode' => null,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Assign a user to a guest cart (used after login/registration).
     */
    #[NoDiscard]
    public function assignUser(string $userId): static
    {
        return clone($this, [
            'userId' => $userId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Merge another cart's items into this one.
     *
     * Items with matching productId have their quantities summed.
     * The source cart's coupon is preserved if this cart has none.
     */
    #[NoDiscard]
    public function merge(self $other): static
    {
        $merged = $this;

        foreach ($other->items as $item) {
            $merged = $merged->addItem($item);
        }

        $coupon = $this->couponCode ?? $other->couponCode;

        return clone($merged, [
            'couponCode' => $coupon,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Calculate the cart subtotal (sum of all line totals before tax/shipping).
     */
    #[NoDiscard]
    public function getSubtotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->items as $item) {
            $total = $total->add($item->lineTotal());
        }

        return $total;
    }

    /**
     * Get the total number of individual units across all lines.
     */
    #[NoDiscard]
    public function getItemCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            $count += $item->quantity;
        }

        return $count;
    }

    /**
     * Get the number of distinct line items (unique products).
     */
    #[NoDiscard]
    public function getLineCount(): int
    {
        return count($this->items);
    }

    /**
     * Whether this cart contains any items.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Whether this is a guest (unauthenticated) cart.
     */
    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * Find the index of an item by product ID, or null if not found.
     */
    private function findItemIndexByProductId(string $productId): ?int
    {
        foreach ($this->items as $index => $item) {
            if ($item->productId === $productId) {
                return $index;
            }
        }

        return null;
    }
}
