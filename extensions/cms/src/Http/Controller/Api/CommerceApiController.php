<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for CMS commerce (products and orders).
 *
 * All endpoints return 404 when commerce is not enabled in CmsConfig.
 */
#[Internal(reason: 'CMS REST API controller; implementation detail')]
final readonly class CommerceApiController
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private OrderRepositoryInterface $orderRepository,
        private CmsConfig $config,
    ) {}

    /**
     * GET /api/v1/products: List products with pagination.
     */
    public function listProducts(ServerRequestInterface $request): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));

        /** @var array<string, mixed> $filters */
        $filters = [];

        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        if (is_string($rawStatus)) {
            $filters['status'] = $rawStatus;
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId !== null) {
            $filters['tenantId'] = $tenantId;
        }

        $products = $this->productRepository->listProducts($filters, $page, $perPage);

        $data = array_map(static fn(Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'status' => $p->status->value,
            'price_amount' => $p->priceAmount,
            'price_currency' => $p->priceCurrency,
            'stock_quantity' => $p->stockQuantity,
            'digital' => $p->digital,
            'created_at' => $p->createdAt->format('c'),
            'updated_at' => $p->updatedAt->format('c'),
        ], $products);

        return Response::json(['data' => $data])
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * GET /api/v1/products/{id}: Show a single product.
     */
    public function showProduct(string $id): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        $product = $this->productRepository->findById($id);

        if ($product === null) {
            return Response::json(['error' => 'Product not found', 'status' => 404], 404);
        }

        return Response::json([
            'data' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'status' => $product->status->value,
                'price_amount' => $product->priceAmount,
                'price_currency' => $product->priceCurrency,
                'tax_category' => $product->taxCategory,
                'stock_quantity' => $product->stockQuantity,
                'digital' => $product->digital,
                'content_id' => $product->contentId,
                'created_at' => $product->createdAt->format('c'),
                'updated_at' => $product->updatedAt->format('c'),
            ],
        ]);
    }

    /**
     * GET /api/v1/orders: List orders with pagination.
     */
    public function listOrders(ServerRequestInterface $request): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        /** @var mixed $apiKey */
        $apiKey = $request->getAttribute('cms_api_key');
        if (!$apiKey instanceof ApiKey) {
            return Response::json(['error' => 'Authentication required', 'status' => 401], 401);
        }

        $params = $request->getQueryParams();
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));

        /** @var array<string, mixed> $filters */
        $filters = [];

        /** @var mixed $rawStatus */
        $rawStatus = $params['status'] ?? null;
        if (is_string($rawStatus)) {
            $filters['status'] = $rawStatus;
        }

        /** @var mixed $rawCustomerId */
        $rawCustomerId = $params['customer_id'] ?? null;
        if (is_string($rawCustomerId)) {
            $filters['customerId'] = $rawCustomerId;
        }

        // Scope the listing to the authenticated key's tenant. A null tenant is a
        // single-tenant deployment; a non-null one constrains the query so one
        // tenant's key can never enumerate another tenant's orders.
        if ($apiKey->tenantId !== null) {
            $filters['tenantId'] = $apiKey->tenantId;
        }

        $orders = $this->orderRepository->listOrders($filters, $page, $perPage);

        $data = array_map(static fn(Order $o) => [
            'id' => $o->id,
            'order_number' => $o->orderNumber,
            'customer_id' => $o->customerId,
            'status' => $o->status->value,
            'total' => $o->total,
            'currency' => $o->currency,
            'payment_status' => $o->paymentStatus->value,
            'created_at' => $o->createdAt->format('c'),
            'updated_at' => $o->updatedAt->format('c'),
        ], $orders);

        return Response::json(['data' => $data])
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * GET /api/v1/orders/{id}: Show a single order.
     *
     * Requires an authenticated API key (CmsApiKeyMiddleware) and returns the
     * order only when it belongs to the caller's tenant. Order ids are otherwise
     * the only secret guarding customer PII (email, billing/shipping address,
     * notes) — an unauthenticated or cross-tenant caller must never receive it.
     */
    public function showOrder(ServerRequestInterface $request, string $id): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        /** @var mixed $apiKey */
        $apiKey = $request->getAttribute('cms_api_key');
        if (!$apiKey instanceof ApiKey) {
            return Response::json(['error' => 'Authentication required', 'status' => 401], 401);
        }

        $order = $this->orderRepository->findById($id);

        // Fail closed: an order from another tenant (or none) is reported as 404,
        // never 403, so ids cannot be probed for existence across tenants.
        if ($order === null || $order->tenantId !== $apiKey->tenantId) {
            return Response::json(['error' => 'Order not found', 'status' => 404], 404);
        }

        return Response::json([
            'data' => [
                'id' => $order->id,
                'order_number' => $order->orderNumber,
                'customer_id' => $order->customerId,
                'customer_email' => $order->customerEmail,
                'status' => $order->status->value,
                'subtotal' => $order->subtotal,
                'tax_amount' => $order->taxAmount,
                'discount_amount' => $order->discountAmount,
                'shipping_amount' => $order->shippingAmount,
                'total' => $order->total,
                'amount_refunded' => $order->amountRefunded,
                'currency' => $order->currency,
                'payment_status' => $order->paymentStatus->value,
                'billing_address' => $order->billingAddress,
                'shipping_address' => $order->shippingAddress,
                'notes' => $order->notes,
                'created_at' => $order->createdAt->format('c'),
                'updated_at' => $order->updatedAt->format('c'),
            ],
        ]);
    }
}
