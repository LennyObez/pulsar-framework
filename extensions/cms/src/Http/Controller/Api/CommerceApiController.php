<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Http\Message\Response;

use function array_map;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for CMS commerce (products and orders).
 *
 * All endpoints return 404 when commerce is not enabled in CmsConfig.
 */
#[Internal(reason: 'CMS REST API controller — implementation detail')]
final readonly class CommerceApiController
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private OrderRepositoryInterface $orderRepository,
        private CmsConfig $config,
    ) {}

    /**
     * GET /api/v1/products — List products with pagination.
     */
    public function listProducts(ServerRequestInterface $request): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        /** @var array<string, mixed> $filters */
        $filters = [];

        if (is_string($params['status'] ?? null)) {
            $filters['status'] = $params['status'];
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
     * GET /api/v1/products/{id} — Show a single product.
     */
    public function showProduct(ServerRequestInterface $request, string $id): Response
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
     * GET /api/v1/orders — List orders with pagination.
     */
    public function listOrders(ServerRequestInterface $request): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        /** @var array<string, mixed> $filters */
        $filters = [];

        if (is_string($params['status'] ?? null)) {
            $filters['status'] = $params['status'];
        }

        if (is_string($params['customer_id'] ?? null)) {
            $filters['customerId'] = $params['customer_id'];
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId !== null) {
            $filters['tenantId'] = $tenantId;
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
     * GET /api/v1/orders/{id} — Show a single order.
     */
    public function showOrder(ServerRequestInterface $request, string $id): Response
    {
        if ($this->config->commerce === null) {
            return Response::json(['error' => 'Commerce is not enabled', 'status' => 404], 404);
        }

        $order = $this->orderRepository->findById($id);

        if ($order === null) {
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
