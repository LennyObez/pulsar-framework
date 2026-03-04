<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Cart;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Public API for cart management.
 *
 * Abstracts cart storage (session, database, Redis) behind a clean contract.
 * Implementations handle cart persistence, guest-to-user migration, and
 * coupon validation.
 */
#[Api(since: '1.0.0')]
interface CartServiceInterface
{
    /**
     * Retrieve the cart for the current session or user.
     *
     * If no cart exists, a new empty cart is created and returned.
     *
     * @param string|null $userId Null for guest carts
     */
    #[NoDiscard]
    public function getCart(?string $userId = null): Cart;

    /**
     * Add a product to the cart.
     *
     * If the product already exists in the cart, the quantity is incremented.
     *
     * @param string|null $userId Null for guest carts
     * @param non-empty-string $productId
     * @param string $productName Human-readable name for display
     * @param int<1, max> $quantity Number of units to add
     * @param Money $unitPrice Price per unit
     * @param array<string, mixed> $metadata Optional variant/attribute data
     */
    public function addItem(
        ?string $userId,
        string $productId,
        string $productName,
        int $quantity,
        Money $unitPrice,
        array $metadata = [],
    ): Cart;

    /**
     * Remove a line item from the cart.
     *
     * @param string|null $userId Null for guest carts
     * @param non-empty-string $itemId Cart line item ID
     */
    public function removeItem(?string $userId, string $itemId): Cart;

    /**
     * Update the quantity of a line item.
     *
     * @param string|null $userId Null for guest carts
     * @param non-empty-string $itemId Cart line item ID
     * @param int<1, max> $quantity New quantity
     */
    public function updateQuantity(?string $userId, string $itemId, int $quantity): Cart;

    /**
     * Apply a coupon code to the cart.
     *
     * @param string|null $userId Null for guest carts
     * @param non-empty-string $couponCode
     *
     * @return Cart The updated cart (coupon validation is deferred to checkout)
     */
    public function applyCoupon(?string $userId, string $couponCode): Cart;

    /**
     * Merge a guest cart into an authenticated user's cart.
     *
     * Called after login/registration to transfer items from the
     * session-based guest cart to the user's persistent cart.
     *
     * @param non-empty-string $userId The newly authenticated user
     */
    public function mergeGuestCart(string $userId): Cart;

    /**
     * Clear all items from the cart.
     *
     * @param string|null $userId Null for guest carts
     */
    public function clearCart(?string $userId): void;
}
