<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ApiKey;
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

        $response = $controller->showProduct('missing');

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
    public function show_order_returns_order_details_for_matching_tenant(): void
    {
        $controller = $this->controller();
        $this->orderRepository->method('findById')->willReturn($this->order('tenant-a'));

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders/order-1')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $controller->showOrder($request, 'order-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{id: string, order_number: string, customer_email: string}} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('order-1', $body['data']['id']);
        self::assertSame('ORD-0001', $body['data']['order_number']);
        self::assertSame('test@example.com', $body['data']['customer_email']);
    }

    #[Test]
    public function show_order_returns_401_without_api_key(): void
    {
        $controller = $this->controller();
        // The controller must not even look up the order without authentication.
        $this->orderRepository->method('findById')->willReturn($this->order('tenant-a'));

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders/order-1');

        $response = $controller->showOrder($request, 'order-1');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function show_order_returns_404_for_a_foreign_tenant_order(): void
    {
        // The order belongs to tenant-b; a tenant-a key must not receive its PII,
        // and the mismatch is a 404 (not 403) so ids cannot be probed.
        $controller = $this->controller();
        $this->orderRepository->method('findById')->willReturn($this->order('tenant-b'));

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders/order-1')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $controller->showOrder($request, 'order-1');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('test@example.com', (string) $response->getBody());
    }

    #[Test]
    public function list_orders_returns_401_without_api_key(): void
    {
        $controller = $this->controller();

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders');

        $response = $controller->listOrders($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function list_orders_scopes_to_the_key_tenant(): void
    {
        $controller = $this->controller();

        $captured = null;
        $this->orderRepository->method('listOrders')->willReturnCallback(
            function (array $filters) use (&$captured): array {
                $captured = $filters;

                return [];
            },
        );

        $request = new ServerRequest(method: 'GET', uri: '/api/v1/orders')
            ->withAttribute('cms_api_key', $this->apiKey('tenant-a'));

        $response = $controller->listOrders($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($captured);
        self::assertSame('tenant-a', $captured['tenantId'] ?? null);
    }

    private function controller(): CommerceApiController
    {
        $config = new CmsConfig(commerce: CommerceConfig::fromArray(['currency' => 'USD']));

        return new CommerceApiController($this->productRepository, $this->orderRepository, $config);
    }

    private function apiKey(?string $tenantId): ApiKey
    {
        return new ApiKey(
            id: 'key-1',
            tenantId: $tenantId,
            name: 'test',
            keyHash: 'hash',
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable(),
            expiresAt: null,
        );
    }

    private function order(?string $tenantId): Order
    {
        $now = new DateTimeImmutable();

        return new Order(
            id: 'order-1',
            tenantId: $tenantId,
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
    }
}
