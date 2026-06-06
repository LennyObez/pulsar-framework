<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Cart\CartServiceInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Http\Message\Response;

use function array_map;
use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function str_contains;

use const ENT_QUOTES;

/**
 * Front-office cart controller for managing the shopping cart.
 *
 * Returns HTML for browser requests (Accept: text/html) and JSON for
 * API/AJAX requests (Accept: application/json). POST endpoints always
 * return JSON for use with fetch/XHR clients.
 */
#[Internal(reason: 'HTTP controller; implementation detail')]
final readonly class CartController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private CartServiceInterface $cartService,
    ) {}

    /**
     * GET /cart
     *
     * Display the cart page or return cart contents as JSON.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(ServerRequestInterface $request): Response
    {
        $userId = $this->resolveUserId($request);
        $cart = $this->cartService->getCart($userId);

        if ($this->wantsJson($request)) {
            return Response::json([
                'cart' => [
                    'id' => $cart->id,
                    'item_count' => $cart->getItemCount(),
                    'line_count' => $cart->getLineCount(),
                    'subtotal' => $cart->getSubtotal()->amount,
                    'currency' => $cart->currency->value,
                    'coupon_code' => $cart->couponCode,
                    'items' => array_map(static fn($item) => [
                        'id' => $item->id,
                        'product_id' => $item->productId,
                        'product_name' => $item->productName,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unitPrice->amount,
                        'line_total' => $item->lineTotal()->amount,
                        'currency' => $item->unitPrice->currency->value,
                        'metadata' => $item->metadata,
                    ], $cart->items),
                ],
            ]);
        }

        return $this->renderCartHtml($cart);
    }

    /**
     * POST /cart/add
     *
     * Add an item to the cart. Returns updated cart as JSON.
     *
     * Expected body: { "product_id": "...", "product_name": "...", "quantity": 1, "unit_price": 1999, "currency": "EUR" }
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function add(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawProductId */
        $rawProductId = $body['product_id'] ?? null;
        $productId = is_string($rawProductId) ? $rawProductId : '';

        if ($productId === '') {
            return Response::json(['error' => 'payments.cart.product_id_required'], 422);
        }

        /** @var mixed $rawProductName */
        $rawProductName = $body['product_name'] ?? null;
        $productName = is_string($rawProductName) ? $rawProductName : '';

        if ($productName === '') {
            return Response::json(['error' => 'payments.cart.product_name_required'], 422);
        }

        /** @var int<1, max> $quantity */
        $quantity = $this->parsePositiveInt($body['quantity'] ?? null, 1);
        $unitPriceValue = $this->parseNonNegativeInt($body['unit_price'] ?? null);

        if ($unitPriceValue === null) {
            return Response::json(['error' => 'payments.cart.unit_price_required'], 422);
        }

        /** @var mixed $rawCurrency */
        $rawCurrency = $body['currency'] ?? null;
        $currencyCode = is_string($rawCurrency) ? $rawCurrency : 'EUR';
        $currency = Currency::tryFrom($currencyCode);

        if ($currency === null) {
            return Response::json(['error' => 'payments.cart.invalid_currency'], 422);
        }

        $unitPrice = Money::of($unitPriceValue, $currency);

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];

        $userId = $this->resolveUserId($request);

        $cart = $this->cartService->addItem(
            $userId,
            $productId,
            $productName,
            $quantity,
            $unitPrice,
            $metadata,
        );

        return Response::json([
            'message' => 'payments.cart.item_added',
            'cart' => $this->cartToJson($cart),
        ]);
    }

    /**
     * POST /cart/remove/{itemId}
     *
     * Remove an item from the cart.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function remove(ServerRequestInterface $request): Response
    {
        $itemId = $this->resolveRouteParam($request, 'itemId');

        if ($itemId === '') {
            return Response::json(['error' => 'payments.cart.item_id_required'], 422);
        }

        $userId = $this->resolveUserId($request);
        $cart = $this->cartService->removeItem($userId, $itemId);

        return Response::json([
            'message' => 'payments.cart.item_removed',
            'cart' => $this->cartToJson($cart),
        ]);
    }

    /**
     * POST /cart/update/{itemId}
     *
     * Update the quantity of a cart item.
     *
     * Expected body: { "quantity": 3 }
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function update(ServerRequestInterface $request): Response
    {
        $itemId = $this->resolveRouteParam($request, 'itemId');

        if ($itemId === '') {
            return Response::json(['error' => 'payments.cart.item_id_required'], 422);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $rawQuantity = $this->parsePositiveInt($body['quantity'] ?? null, null);

        if ($rawQuantity === null) {
            return Response::json(['error' => 'payments.cart.quantity_required'], 422);
        }

        /** @var int<1, max> $rawQuantity */
        $userId = $this->resolveUserId($request);
        $cart = $this->cartService->updateQuantity($userId, $itemId, $rawQuantity);

        return Response::json([
            'message' => 'payments.cart.quantity_updated',
            'cart' => $this->cartToJson($cart),
        ]);
    }

    /**
     * POST /cart/coupon
     *
     * Apply a coupon code to the cart.
     *
     * Expected body: { "coupon_code": "SAVE10" }
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function applyCoupon(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawCouponCode */
        $rawCouponCode = $body['coupon_code'] ?? null;
        $couponCode = is_string($rawCouponCode) ? $rawCouponCode : '';

        if ($couponCode === '') {
            return Response::json(['error' => 'payments.cart.coupon_code_required'], 422);
        }

        $userId = $this->resolveUserId($request);
        $cart = $this->cartService->applyCoupon($userId, $couponCode);

        return Response::json([
            'message' => 'payments.cart.coupon_applied',
            'cart' => $this->cartToJson($cart),
        ]);
    }

    /**
     * Serialize cart to a JSON-safe array.
     *
     * @return array<string, mixed>
     */
    private function cartToJson(\Pulsar\Extension\Payments\Cart\Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'item_count' => $cart->getItemCount(),
            'line_count' => $cart->getLineCount(),
            'subtotal' => $cart->getSubtotal()->amount,
            'currency' => $cart->currency->value,
            'coupon_code' => $cart->couponCode,
        ];
    }

    /**
     * Render the cart page as HTML for browser visitors.
     */
    private function renderCartHtml(\Pulsar\Extension\Payments\Cart\Cart $cart): Response
    {
        $itemsHtml = '';

        foreach ($cart->items as $item) {
            $name = htmlspecialchars($item->productName, ENT_QUOTES, 'UTF-8');
            $unitFormatted = $item->unitPrice->format();
            $lineFormatted = $item->lineTotal()->format();
            $symbol = $item->unitPrice->currency->symbol();
            $safeId = htmlspecialchars($item->id, ENT_QUOTES, 'UTF-8');

            $itemsHtml .= <<<HTML
                    <tr>
                        <td>{$name}</td>
                        <td>{$symbol}{$unitFormatted}</td>
                        <td>{$item->quantity}</td>
                        <td>{$symbol}{$lineFormatted}</td>
                        <td>
                            <form method="post" action="/cart/remove/{$safeId}" class="pui-inline">
                                <button type="submit" class="pui-btn pui-btn--sm pui-btn--danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                HTML;
        }

        $subtotalFormatted = $cart->getSubtotal()->format();
        $symbol = $cart->currency->symbol();
        $couponBadge = $cart->couponCode !== null
            ? '<span class="pui-badge pui-badge--success">Coupon: ' . htmlspecialchars($cart->couponCode, ENT_QUOTES, 'UTF-8') . '</span>'
            : '';

        $emptyMessage = $cart->isEmpty()
            ? '<div class="pui-alert pui-alert--info"><p>Your cart is empty.</p></div>'
            : '';

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Shopping Cart</title>
                    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
                </head>
                <body>
                    <main class="pui-container">
                        <h1>Shopping Cart</h1>
                        {$emptyMessage}
                        {$couponBadge}
                        <table class="pui-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$itemsHtml}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3"><strong>Subtotal</strong></td>
                                    <td><strong>{$symbol}{$subtotalFormatted}</strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <div class="pui-flex pui-gap-md pui-mt-lg">
                            <a href="/" class="pui-btn pui-btn--outline">Continue Shopping</a>
                            <a href="/checkout" class="pui-btn pui-btn--primary">Proceed to Checkout</a>
                        </div>
                    </main>
                </body>
                </html>
                HTML,
        );
    }

    private function resolveUserId(ServerRequestInterface $request): ?string
    {
        /** @var mixed $userId */
        $userId = $request->getAttribute('user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    private function resolveRouteParam(ServerRequestInterface $request, string $param): string
    {
        /** @var mixed $value */
        $value = $request->getAttribute($param);

        return is_string($value) ? $value : '';
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return $accept === 'application/json' || str_contains($accept, 'application/json');
    }

    private function parsePositiveInt(mixed $value, ?int $default): ?int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_numeric($value)) {
            $int = (int) $value;

            return $int >= 1 ? $int : $default;
        }

        return $default;
    }

    private function parseNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return null;
    }
}
