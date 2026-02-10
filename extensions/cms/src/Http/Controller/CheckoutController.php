<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;

use function array_map;
use function filter_var;
use function is_array;
use function is_string;

use const FILTER_VALIDATE_EMAIL;

/**
 * Public-facing checkout controller.
 *
 * Handles cart validation, order creation, payment processing, and
 * order confirmation display. Cart state is managed via session attributes.
 */
#[Internal(reason: 'CMS HTTP controller — implementation detail')]
final readonly class CheckoutController
{
    public function __construct(
        private CheckoutServiceInterface $checkout,
    ) {}

    public function show(ServerRequestInterface $request): Response
    {
        /** @var list<array{productId: string, quantity: int, unitPrice: int}>|null $cartItems */
        $cartItems = $request->getAttribute('cart_items');

        if (!is_array($cartItems) || $cartItems === []) {
            return Response::json(['error' => 'Cart is empty'], 400);
        }

        $validation = $this->checkout->validateCart($cartItems);

        if (!$validation->isValid) {
            return Response::json([
                'error' => 'Cart validation failed',
                'errors' => $validation->errors,
            ], 422);
        }

        return Response::json([
            'items' => array_map(static fn(array $item) => [
                'product_id' => $item['productId'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unitPrice'],
                'currency' => $item['currency'],
            ], $validation->validatedItems),
        ]);
    }

    public function process(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $email = (string) ($body['email'] ?? '');

        if ($email === '' || !$this->isValidEmail($email)) {
            return Response::json(['error' => 'A valid email address is required'], 400);
        }

        /** @var list<array{productId: string, quantity: int, unitPrice: int}>|null $cartItems */
        $cartItems = $request->getAttribute('cart_items');

        if (!is_array($cartItems) || $cartItems === []) {
            return Response::json(['error' => 'Cart is empty'], 400);
        }

        /** @var array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string}|null $billingAddress */
        $billingAddress = is_array($body['billing_address'] ?? null) ? $body['billing_address'] : null;

        if ($billingAddress === null || !is_string($billingAddress['line1'] ?? null) || ($billingAddress['line1'] ?? '') === '') {
            return Response::json(['error' => 'Billing address is required'], 400);
        }

        /** @var array{line1: string, line2?: string, city: string, region?: string, postalCode: string, country: string}|null $shippingAddress */
        $shippingAddress = is_array($body['shipping_address'] ?? null) ? $body['shipping_address'] : null;

        $couponCode = is_string($body['coupon_code'] ?? null) && $body['coupon_code'] !== ''
            ? $body['coupon_code']
            : null;

        /** @var string|null $customerId */
        $customerId = $request->getAttribute('customer_id');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $order = $this->checkout->createOrder(
                $cartItems,
                $email,
                $billingAddress,
                $shippingAddress,
                $couponCode,
                $customerId,
                $tenantId,
            );

            /** @var array<string, mixed> $paymentData */
            $paymentData = is_array($body['payment'] ?? null) ? $body['payment'] : [];

            $paymentResult = $this->checkout->processPayment($order->id, $paymentData);

            if ($paymentResult->requiresRedirect && $paymentResult->redirectUrl !== null) {
                return Response::json([
                    'order_id' => $order->id,
                    'status' => 'requires_redirect',
                    'redirect_url' => $paymentResult->redirectUrl,
                ]);
            }

            if ($paymentResult->success) {
                return Response::json([
                    'order_id' => $order->id,
                    'order_number' => $order->orderNumber,
                    'status' => 'success',
                ]);
            }

            return Response::json([
                'order_id' => $order->id,
                'status' => 'pending',
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function success(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $orderId = (string) ($params['order_id'] ?? '');

        if ($orderId === '') {
            return Response::json(['error' => 'Order ID is required'], 400);
        }

        return Response::json([
            'order_id' => $orderId,
            'status' => 'confirmed',
            'message' => 'Thank you for your order.',
        ]);
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
