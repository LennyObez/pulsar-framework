<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Internal\Cart;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Cart\Cart;
use Pulsar\Extension\Payments\Cart\CartItem;
use Pulsar\Extension\Payments\Cart\CartServiceInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Security\Session\SessionInterface;

use function array_map;
use function bin2hex;
use function is_array;
use function is_string;
use function random_bytes;

/**
 * Session-backed cart service.
 *
 * Stores serialized cart data in the user's HTTP session, making it
 * suitable for traditional server-rendered applications. Guest carts
 * are stored under a session key and migrated to the user's key on
 * authentication via {@see mergeGuestCart()}.
 */
#[Internal(reason: 'Session-based cart persistence; implementation detail')]
final readonly class SessionCartService implements CartServiceInterface
{
    private const string SESSION_KEY_GUEST = 'payments.cart.guest';
    private const string SESSION_KEY_PREFIX = 'payments.cart.user.';

    public function __construct(
        private SessionInterface $session,
        private Currency $defaultCurrency = Currency::EUR,
    ) {}

    public function getCart(?string $userId = null): Cart
    {
        $key = $this->sessionKey($userId);
        /** @var mixed $data */
        $data = $this->session->get($key);

        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            return $this->deserializeCart($data);
        }

        return Cart::create($userId, $this->defaultCurrency);
    }

    public function addItem(
        ?string $userId,
        string $productId,
        string $productName,
        int $quantity,
        Money $unitPrice,
        array $metadata = [],
    ): Cart {
        $cart = $this->getCart($userId);

        $item = new CartItem(
            id: bin2hex(random_bytes(16)),
            productId: $productId,
            productName: $productName,
            quantity: $quantity,
            unitPrice: $unitPrice,
            metadata: $metadata,
        );

        $cart = $cart->addItem($item);
        $this->persist($userId, $cart);

        return $cart;
    }

    public function removeItem(?string $userId, string $itemId): Cart
    {
        $cart = $this->getCart($userId);
        $cart = $cart->removeItem($itemId);
        $this->persist($userId, $cart);

        return $cart;
    }

    public function updateQuantity(?string $userId, string $itemId, int $quantity): Cart
    {
        $cart = $this->getCart($userId);
        $cart = $cart->updateQuantity($itemId, $quantity);
        $this->persist($userId, $cart);

        return $cart;
    }

    public function applyCoupon(?string $userId, string $couponCode): Cart
    {
        $cart = $this->getCart($userId);
        $cart = $cart->withCoupon($couponCode);
        $this->persist($userId, $cart);

        return $cart;
    }

    public function mergeGuestCart(string $userId): Cart
    {
        $guestCart = $this->getCart(null);
        $userCart = $this->getCart($userId);

        if ($guestCart->isEmpty()) {
            return $userCart;
        }

        $merged = $userCart->merge($guestCart);
        $this->persist($userId, $merged);

        // Clear the guest cart after merging
        $this->session->remove(self::SESSION_KEY_GUEST);

        return $merged;
    }

    public function clearCart(?string $userId): void
    {
        $key = $this->sessionKey($userId);
        $this->session->remove($key);
    }

    /**
     * Persist a cart to the session.
     */
    private function persist(?string $userId, Cart $cart): void
    {
        $key = $this->sessionKey($userId);
        $this->session->set($key, $this->serializeCart($cart));
    }

    /**
     * Determine the session key for the given user context.
     */
    private function sessionKey(?string $userId): string
    {
        if ($userId === null) {
            return self::SESSION_KEY_GUEST;
        }

        return self::SESSION_KEY_PREFIX . $userId;
    }

    /**
     * Serialize a cart to a session-storable array.
     *
     * @return array<string, mixed>
     */
    private function serializeCart(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'userId' => $cart->userId,
            'currency' => $cart->currency->value,
            'couponCode' => $cart->couponCode,
            'createdAt' => $cart->createdAt->format('c'),
            'updatedAt' => $cart->updatedAt->format('c'),
            'items' => array_map(
                static fn(CartItem $item): array => $item->toArray(),
                $cart->items,
            ),
        ];
    }

    /**
     * Deserialize a cart from its session array form.
     *
     * @param array{
     *     id?: string,
     *     userId?: string|null,
     *     currency?: string,
     *     couponCode?: string|null,
     *     createdAt?: string,
     *     updatedAt?: string,
     *     items?: list<array{id?: string, productId?: string, productName?: string, quantity?: int, unitPriceAmount?: int, currency?: string, metadata?: array<string, mixed>}>,
     * } $data
     */
    private function deserializeCart(array $data): Cart
    {
        $items = [];

        foreach ($data['items'] ?? [] as $rawItem) {
            if (isset($rawItem['id'], $rawItem['productId']) && $rawItem['id'] !== '' && $rawItem['productId'] !== '') {
                /** @var array{id: non-empty-string, productId: non-empty-string, productName: string, quantity: int<1, max>, unitPriceAmount: int, currency: string, metadata?: array<string, mixed>} $validItem */
                $validItem = $rawItem;
                $items[] = CartItem::fromArray($validItem);
            }
        }

        $rawUserId = $data['userId'] ?? null;
        $userId = is_string($rawUserId) && $rawUserId !== '' ? $rawUserId : null;
        $rawCouponCode = $data['couponCode'] ?? null;
        $couponCode = is_string($rawCouponCode) && $rawCouponCode !== '' ? $rawCouponCode : null;

        $currencyCode = $data['currency'] ?? 'EUR';
        $currency = Currency::tryFrom($currencyCode) ?? $this->defaultCurrency;

        /** @var mixed $rawCartId */
        $rawCartId = $data['id'] ?? '';
        $cartId = is_string($rawCartId) && $rawCartId !== '' ? $rawCartId : bin2hex(random_bytes(16));

        return new Cart(
            id: $cartId,
            userId: $userId,
            items: $items,
            currency: $currency,
            couponCode: $couponCode,
            createdAt: new DateTimeImmutable($data['createdAt'] ?? 'now'),
            updatedAt: new DateTimeImmutable($data['updatedAt'] ?? 'now'),
        );
    }
}
