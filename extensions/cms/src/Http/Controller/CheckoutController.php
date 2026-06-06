<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function filter_var;
use function is_array;
use function is_string;
use function str_contains;

use const FILTER_VALIDATE_EMAIL;

/**
 * Public-facing checkout controller.
 *
 * Handles cart validation, order creation, payment processing, and
 * order confirmation display. Cart state is managed via session attributes.
 *
 * Returns HTML for browser requests (Accept: text/html) and JSON for
 * API requests (Accept: application/json).
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class CheckoutController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private CheckoutServiceInterface $checkout,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function show(ServerRequestInterface $request): Response
    {
        /** @var list<array{productId: string, quantity: int, unitPrice: int}>|null $cartItems */
        $cartItems = $request->getAttribute('cart_items');

        if (!is_array($cartItems) || $cartItems === []) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => 'Cart is empty'], 400);
            }

            return $this->renderCheckoutHtml($request, [], 'Cart is empty');
        }

        $validation = $this->checkout->validateCart($cartItems);

        if (!$validation->isValid) {
            if ($this->wantsJson($request)) {
                return Response::json([
                    'error' => 'Cart validation failed',
                    'errors' => $validation->errors,
                ], 422);
            }

            return $this->renderCheckoutHtml($request, [], 'Cart validation failed');
        }

        $items = array_map(static fn(array $item) => [
            'product_id' => $item['productId'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unitPrice'],
            'currency' => $item['currency'],
        ], $validation->validatedItems);

        if ($this->wantsJson($request)) {
            return Response::json(['items' => $items]);
        }

        return $this->renderCheckoutHtml($request, $items);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function process(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawEmail */
        $rawEmail = $body['email'] ?? null;
        $email = is_string($rawEmail) ? $rawEmail : '';

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

        /** @var mixed $rawCouponCode */
        $rawCouponCode = $body['coupon_code'] ?? null;
        $couponCode = is_string($rawCouponCode) && $rawCouponCode !== ''
            ? $rawCouponCode
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function success(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawOrderId */
        $rawOrderId = $params['order_id'] ?? null;
        $orderId = is_string($rawOrderId) ? $rawOrderId : '';

        if ($orderId === '') {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => 'Order ID is required'], 400);
            }

            return $this->renderSuccessHtml($request, '', 'Order ID is required');
        }

        if ($this->wantsJson($request)) {
            return Response::json([
                'order_id' => $orderId,
                'status' => 'confirmed',
                'message' => 'Thank you for your order.',
            ]);
        }

        return $this->renderSuccessHtml($request, $orderId);
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if the request prefers JSON over HTML.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return $accept === 'application/json' || str_contains($accept, 'application/json');
    }

    /**
     * Render the checkout page as HTML.
     *
     * @param list<array<string, mixed>> $items
     */
    private function renderCheckoutHtml(
        ServerRequestInterface $request,
        array $items,
        ?string $error = null,
    ): Response {
        if ($this->templateEngine !== null) {
            /** @var mixed $csrfToken */
            $csrfToken = $request->getAttribute('csrf_token', '');

            $html = $this->templateEngine->render('cms::checkout.show', [
                'items' => $items,
                'subtotal' => 0,
                'shippingAmount' => 0,
                'taxAmount' => 0,
                'total' => 0,
                'currency' => 'USD',
                'csrfToken' => is_string($csrfToken) ? $csrfToken : '',
                'email' => '',
                'appliedCoupon' => '',
                'billingAddress' => ['name' => '', 'line1' => '', 'line2' => '', 'city' => '', 'postalCode' => '', 'country' => ''],
                'shippingAddress' => ['name' => '', 'line1' => '', 'line2' => '', 'city' => '', 'postalCode' => '', 'country' => ''],
                'error' => $error,
            ]);

            $statusCode = $error !== null ? 400 : 200;

            return Response::html($html, $statusCode);
        }

        // Inline fallback when no template engine is available
        $errorHtml = $error !== null
            ? '<div class="alert alert-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Checkout</title>
                </head>
                <body>
                    <main>
                        <h1>Checkout</h1>
                        $errorHtml
                        <p>Complete your purchase below.</p>
                    </main>
                </body>
                </html>
                HTML,
            $error !== null ? 400 : 200,
        );
    }

    /**
     * Render the order success page as HTML.
     */
    private function renderSuccessHtml(
        ServerRequestInterface $request,
        string $orderId,
        ?string $error = null,
    ): Response {
        if ($this->templateEngine !== null) {
            $html = $this->templateEngine->render('cms::checkout.success', [
                'orderId' => $orderId,
                'error' => $error,
            ]);

            return Response::html($html, $error !== null ? 400 : 200);
        }

        if ($error !== null) {
            $safeError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');

            return Response::html(
                <<<HTML
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="utf-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1">
                        <title>Checkout Error</title>
                    </head>
                    <body>
                        <main>
                            <h1>Checkout Error</h1>
                            <p>$safeError</p>
                            <a href="/checkout">Return to checkout</a>
                        </main>
                    </body>
                    </html>
                    HTML,
                400,
            );
        }

        $safeOrderId = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Order Confirmed</title>
                </head>
                <body>
                    <main>
                        <h1>Thank You</h1>
                        <p>Your order <strong>$safeOrderId</strong> has been confirmed.</p>
                        <a href="/">Continue Shopping</a>
                    </main>
                </body>
                </html>
                HTML,
        );
    }
}
