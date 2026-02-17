<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Http\Controller\Admin\OrderController;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(OrderController::class)]
final class OrderControllerTest extends TestCase
{
    #[Test]
    public function index_returns_order_list(): void
    {
        $order = $this->createOrder('order-1');

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('listOrders')->willReturn([$order]);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $ordersList */
        $ordersList = $body['orders'];
        self::assertCount(1, $ordersList);
        self::assertSame('order-1', $ordersList[0]['id']);
        self::assertSame('ORD-001', $ordersList[0]['order_number']);
        self::assertSame('confirmed', $ordersList[0]['status']);
        self::assertSame('paid', $ordersList[0]['payment_status']);
    }

    #[Test]
    public function show_returns_order_with_items(): void
    {
        $order = $this->createOrder('order-1');

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $orderItems->method('findByOrder')->willReturn([]);

        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'order-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $orderData */
        $orderData = $body['order'];
        self::assertSame('order-1', $orderData['id']);
        self::assertSame('customer@example.com', $orderData['customer_email']);
        self::assertSame(15000, $orderData['total']);
    }

    #[Test]
    public function show_returns_404_when_order_not_found(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn(null);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function refund_returns_400_when_amount_not_positive(): void
    {
        $order = $this->createOrder('order-1');

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['amount' => 0, 'reason' => 'Test refund'],
        );

        $response = $controller->refund($request, 'order-1');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('positive', $body['error']);
    }

    #[Test]
    public function refund_returns_400_when_reason_empty(): void
    {
        $order = $this->createOrder('order-1');

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['amount' => 500, 'reason' => ''],
        );

        $response = $controller->refund($request, 'order-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function refund_returns_404_when_order_not_found(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn(null);

        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(
            stepUp: true,
            parsedBody: ['amount' => 500, 'reason' => 'Damaged goods'],
        );

        $response = $controller->refund($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function export_returns_csv_when_format_specified(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $exportService->method('exportCsv')->willReturn("id,order_number\norder-1,ORD-001\n");
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(queryParams: ['format' => 'csv']);

        $response = $controller->export($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function export_returns_json_when_format_specified(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $exportService->method('exportJson')->willReturn('{"orders":[]}');
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(queryParams: ['format' => 'json']);

        $response = $controller->export($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function export_returns_formats_when_no_format_specified(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->export($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body['formats']);
        self::assertContains('csv', $body['formats']);
        self::assertContains('json', $body['formats']);
    }

    #[Test]
    public function export_returns_400_for_unsupported_format(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );
        $request = $this->createAuthenticatedRequest(queryParams: ['format' => 'xml']);

        $response = $controller->export($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
        );

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orderItems = $this->createStub(OrderItemRepositoryInterface::class);
        $exportService = $this->createStub(OrderExportServiceInterface::class);
        $orderService = $this->createOrderService($orders);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new OrderController(
            orders: $orders,
            orderItems: $orderItems,
            orderService: $orderService,
            exportService: $exportService,
            gate: $gate,
        );
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createOrder(string $id): Order
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Order(
            id: $id,
            tenantId: null,
            orderNumber: 'ORD-001',
            customerId: 'cust-1',
            customerEmail: 'customer@example.com',
            status: OrderStatus::Confirmed,
            subtotal: 12000,
            taxAmount: 2000,
            discountAmount: 0,
            shippingAmount: 1000,
            shippingMethod: null,
            total: 15000,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: 'pi_test_123',
            paymentStatus: PaymentStatus::Paid,
            billingAddress: [
                'line1' => '123 Main St',
                'city' => 'Anytown',
                'postalCode' => '12345',
                'country' => 'US',
            ],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createOrderService(OrderRepositoryInterface $orders): OrderService
    {
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);
        $digitalDelivery = $this->createStub(DigitalDeliveryServiceInterface::class);
        $db = $this->createStub(ConnectionInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);

        return new OrderService(
            orders: $orders,
            invoiceService: $invoiceService,
            digitalDelivery: $digitalDelivery,
            db: $db,
            events: $events,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/orders');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/orders');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
