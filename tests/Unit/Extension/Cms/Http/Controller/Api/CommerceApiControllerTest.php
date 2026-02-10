<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Http\Controller\Api\CommerceApiController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

#[CoversClass(CommerceApiController::class)]
final class CommerceApiControllerTest extends TestCase
{
    private ProductRepositoryInterface&Stub $productRepository;
    private OrderRepositoryInterface&Stub $orderRepository;

    protected function setUp(): void
    {
        $this->productRepository = $this->createStub(ProductRepositoryInterface::class);
        $this->orderRepository = $this->createStub(OrderRepositoryInterface::class);
    }

    #[Test]
    public function list_products_returns_404_when_commerce_disabled(): void
    {
        $config = new CmsConfig(commerce: null);
        $controller = new CommerceApiController($this->productRepository, $this->orderRepository, $config);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/products');

        $response = $controller->listProducts($request);

        self::assertSame(404, $response->getStatusCode());

        /** @var array{error: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Commerce is not enabled', $body['error']);
    }

    #[Test]
    public function list_products_returns_products_when_commerce_enabled(): void
    {
        $config = new CmsConfig(commerce: CommerceConfig::fromArray(['currency' => 'USD']));
        $controller = new CommerceApiController($this->productRepository, $this->orderRepository, $config);

        $now = new DateTimeImmutable();
        $product = new Product(
            id: 'prod-1',
            tenantId: null,
            sku: 'SKU-001',
            status: ProductStatus::Active,
            priceAmount: 2999,
            priceCurrency: 'USD',
            taxCategory: null,
            stockQuantity: 50,
            digital: false,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->productRepository->method('listProducts')->willReturn([$product]);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/products');

        $response = $controller->listProducts($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: list<array{id: string, sku: string}>} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('prod-1', $body['data'][0]['id']);
        self::assertSame('SKU-001', $body['data'][0]['sku']);
    }

    #[Test]
    public function show_product_returns_404_for_missing_product(): void
    {
        $config = new CmsConfig(commerce: CommerceConfig::fromArray(['currency' => 'USD']));
        $controller = new CommerceApiController($this->productRepository, $this->orderRepository, $config);

        $this->productRepository->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/products/missing');

        $response = $controller->showProduct($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function list_orders_returns_404_when_commerce_disabled(): void
    {
        $config = new CmsConfig(commerce: null);
        $controller = new CommerceApiController($this->productRepository, $this->orderRepository, $config);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders');

        $response = $controller->listOrders($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function show_order_returns_order_details(): void
    {
        $config = new CmsConfig(commerce: CommerceConfig::fromArray(['currency' => 'USD']));
        $controller = new CommerceApiController($this->productRepository, $this->orderRepository, $config);

        $now = new DateTimeImmutable();
        $order = new Order(
            id: 'order-1',
            tenantId: null,
            orderNumber: 'ORD-0001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            status: OrderStatus::Confirmed,
            subtotal: 2999,
            taxAmount: 450,
            discountAmount: 0,
            shippingAmount: 500,
            shippingMethod: ShippingMethod::Standard,
            total: 3949,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: 'pi_123',
            paymentStatus: PaymentStatus::Paid,
            billingAddress: ['line1' => '123 Main St', 'city' => 'Springfield', 'postalCode' => '12345', 'country' => 'US'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->orderRepository->method('findById')->willReturn($order);

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders/order-1');

        $response = $controller->showOrder($request, 'order-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, order_number: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('order-1', $body['data']['id']);
        self::assertSame('ORD-0001', $body['data']['order_number']);
    }
}
