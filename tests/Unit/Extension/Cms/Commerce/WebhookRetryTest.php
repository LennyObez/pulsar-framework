<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookHandler;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookRetryJob;
use Pulsar\Extension\Cms\Internal\Scheduler\WebhookRetryCleanupJob;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Scheduler\JobContext as SchedulerJobContext;
use Pulsar\Scheduler\JobStatus;
use RuntimeException;

use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function time;
use function uniqid;

use const JSON_THROW_ON_ERROR;

#[CoversClass(WebhookRetryJob::class)]
#[CoversClass(WebhookHandler::class)]
#[CoversClass(WebhookRetryCleanupJob::class)]
final class WebhookRetryTest extends TestCase
{
    private OrderRepositoryInterface&Stub $orders;
    private PaymentGateway&Stub $paymentGateway;
    private ConnectionInterface&Stub $webhookConnection;

    protected function setUp(): void
    {
        $this->orders = $this->createStub(OrderRepositoryInterface::class);
        $this->paymentGateway = $this->createStub(PaymentGateway::class);
        $this->webhookConnection = $this->createStub(ConnectionInterface::class);

        // Default: signature passes, no duplicate events
        $this->paymentGateway->method('verifyWebhookSignature')->willReturn(true);

        $this->webhookConnection->method('query')->willReturn(new Result([]));

        // transaction() must actually run the callback (and propagate any
        // exception it throws) so the handler's retry-on-failure path can be
        // exercised. A bare stub would return null without invoking it.
        $this->webhookConnection->method('transaction')->willReturnCallback(
            fn(callable $callback): mixed => $callback($this->webhookConnection),
        );
    }

    public function testRetryJobDispatchesRetryOnFailureWithIncrementedCount(): void
    {
        $orderServiceDb = $this->createStub(ConnectionInterface::class);
        $orderServiceDb->method('execute')->willThrowException(new RuntimeException('Connection timeout'));
        $this->orders->method('findById')->willReturn($this->makePendingOrder('order-1'));

        $orderService = $this->makeOrderService($orderServiceDb);
        $webhookHandler = $this->makeHandler($orderService);

        /** @var QueueDriverInterface&MockObject $queueDriver */
        $queueDriver = $this->createMock(QueueDriverInterface::class);
        $queueDriver->expects(self::once())
            ->method('push')
            ->with(
                'cms-webhooks',
                WebhookRetryJob::class,
                self::callback(static function (string $payload): bool {
                    $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    assert(is_array($data));

                    return $data['retryCount'] === 2 && $data['maxRetries'] === 5;
                }),
                self::anything(),
            );

        $payload = $this->makePayload('payment_intent.succeeded', [
            'id' => 'pi_123',
            'metadata' => ['orderId' => 'order-1'],
        ]);

        $job = new WebhookRetryJob(
            eventId: 'evt_123',
            payload: $payload,
            signature: 'sig_abc',
            retryCount: 1,
            maxRetries: 5,
            webhookHandler: $webhookHandler,
            queueDriver: $queueDriver,
        );

        $job->handle(new JobContext('job-1', 'cms-webhooks', 1, 1));
    }

    public function testRetryJobDoesNotRetryAfterMaxRetriesExceeded(): void
    {
        $orderServiceDb = $this->createStub(ConnectionInterface::class);
        $orderServiceDb->method('execute')->willThrowException(new RuntimeException('Permanent failure'));
        $this->orders->method('findById')->willReturn($this->makePendingOrder('order-1'));

        $orderService = $this->makeOrderService($orderServiceDb);
        $webhookHandler = $this->makeHandler($orderService);

        /** @var QueueDriverInterface&MockObject $queueDriver */
        $queueDriver = $this->createMock(QueueDriverInterface::class);
        $queueDriver->expects(self::never())->method('push');

        /** @var AuditLoggerInterface&MockObject $auditLogger */
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                null,
                'cms.commerce.webhook.retry_exhausted',
                self::anything(),
                self::callback(static fn(array $meta): bool => $meta['retry_count'] === 5 && $meta['max_retries'] === 5),
            );

        $payload = $this->makePayload('payment_intent.succeeded', [
            'id' => 'pi_456',
            'metadata' => ['orderId' => 'order-1'],
        ]);

        $job = new WebhookRetryJob(
            eventId: 'evt_456',
            payload: $payload,
            signature: 'sig_def',
            retryCount: 4,
            maxRetries: 5,
            webhookHandler: $webhookHandler,
            queueDriver: $queueDriver,
            auditLogger: $auditLogger,
        );

        $job->handle(new JobContext('job-2', 'cms-webhooks', 1, 1));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function backoffProvider(): iterable
    {
        yield 'retry 0 → 60s' => [0, 60];
        yield 'retry 1 → 120s' => [1, 120];
        yield 'retry 2 → 240s' => [2, 240];
        yield 'retry 3 → 480s' => [3, 480];
        yield 'retry 4 → 960s' => [4, 960];
        yield 'retry 5 → 1920s' => [5, 1920];
        yield 'retry 6 → 3600s (capped)' => [6, 3600];
        yield 'retry 10 → 3600s (capped)' => [10, 3600];
    }

    #[DataProvider('backoffProvider')]
    public function testBackoffCalculation(int $retryCount, int $expectedDelay): void
    {
        self::assertSame($expectedDelay, WebhookRetryJob::calculateDelay($retryCount));
    }

    public function testWebhookHandlerDispatchesRetryJobOnProcessingFailure(): void
    {
        $orderServiceDb = $this->createStub(ConnectionInterface::class);
        $orderServiceDb->method('execute')->willThrowException(new RuntimeException('DB down'));
        $this->orders->method('findById')->willReturn($this->makePendingOrder('order-1'));

        $orderService = $this->makeOrderService($orderServiceDb);

        /** @var QueueDriverInterface&MockObject $queueDriver */
        $queueDriver = $this->createMock(QueueDriverInterface::class);
        $queueDriver->expects(self::once())
            ->method('push')
            ->with(
                'cms-webhooks',
                WebhookRetryJob::class,
                self::anything(),
                self::anything(),
            );

        $handler = new WebhookHandler(
            orderService: $orderService,
            orders: $this->orders,
            paymentGateway: $this->paymentGateway,
            connection: $this->webhookConnection,
            auditLogger: $this->createStub(AuditLoggerInterface::class),
            queueDriver: $queueDriver,
        );

        $payload = $this->makePayload('payment_intent.succeeded', [
            'id' => 'pi_123',
            'metadata' => ['orderId' => 'order-1'],
        ]);

        $handler->handle($payload, 'valid-sig');
    }

    public function testWebhookHandlerReThrowsWhenNoQueueAvailable(): void
    {
        $orderServiceDb = $this->createStub(ConnectionInterface::class);
        $orderServiceDb->method('execute')->willThrowException(new RuntimeException('DB down'));
        $this->orders->method('findById')->willReturn($this->makePendingOrder('order-1'));

        $orderService = $this->makeOrderService($orderServiceDb);

        $handler = new WebhookHandler(
            orderService: $orderService,
            orders: $this->orders,
            paymentGateway: $this->paymentGateway,
            connection: $this->webhookConnection,
            auditLogger: $this->createStub(AuditLoggerInterface::class),
            queueDriver: null,
        );

        $payload = $this->makePayload('payment_intent.succeeded', [
            'id' => 'pi_123',
            'metadata' => ['orderId' => 'order-1'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB down');

        $handler->handle($payload, 'valid-sig');
    }

    public function testWebhookHandlerDoesNotDispatchRetryForSignatureFailure(): void
    {
        $this->paymentGateway = $this->createStub(PaymentGateway::class);
        $this->paymentGateway->method('verifyWebhookSignature')->willReturn(false);

        $orderService = $this->makeOrderService($this->createStub(ConnectionInterface::class));

        /** @var QueueDriverInterface&MockObject $queueDriver */
        $queueDriver = $this->createMock(QueueDriverInterface::class);
        $queueDriver->expects(self::never())->method('push');

        $handler = new WebhookHandler(
            orderService: $orderService,
            orders: $this->orders,
            paymentGateway: $this->paymentGateway,
            connection: $this->webhookConnection,
            auditLogger: $this->createStub(AuditLoggerInterface::class),
            queueDriver: $queueDriver,
        );

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Invalid webhook signature');

        $handler->handle('{}', 'bad-sig');
    }

    public function testCleanupJobDeletesOldEvents(): void
    {
        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                'DELETE FROM cms_webhook_events WHERE processed_at < :cutoff',
                self::callback(static fn(array $params): bool => isset($params['cutoff'])),
            )
            ->willReturn(15);

        $job = new WebhookRetryCleanupJob($connection);

        $result = $job->execute(new SchedulerJobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        ));

        self::assertSame(JobStatus::Success, $result->status);
        self::assertStringContainsString('15 record(s) purged', $result->output);
    }

    public function testCleanupJobMetadata(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $job = new WebhookRetryCleanupJob($connection);

        self::assertSame('cms:webhook-retry-cleanup', $job->getName());
        self::assertSame('0 2 * * *', $job->getSchedule()->expression);
        self::assertNotEmpty($job->getDescription());
    }

    public function testRetryJobQueueMetadata(): void
    {
        $orderService = $this->makeOrderService($this->createStub(ConnectionInterface::class));
        $webhookHandler = $this->makeHandler($orderService);

        $job = new WebhookRetryJob(
            eventId: 'evt_x',
            payload: '{}',
            signature: 'sig',
            retryCount: 0,
            maxRetries: 5,
            webhookHandler: $webhookHandler,
            queueDriver: $this->createStub(QueueDriverInterface::class),
        );

        self::assertSame('cms-webhooks', $job->queue());
        self::assertSame(1, $job->maxAttempts());
        self::assertSame(30, $job->timeout());
    }

    public function testFromPayloadReconstructsJob(): void
    {
        $orderService = $this->makeOrderService($this->createStub(ConnectionInterface::class));
        $webhookHandler = $this->makeHandler($orderService);

        $data = [
            'eventId' => 'evt_rebuild',
            'payload' => '{"type":"test"}',
            'signature' => 'sig_rebuild',
            'retryCount' => 3,
            'maxRetries' => 5,
        ];

        $job = WebhookRetryJob::fromPayload(
            $data,
            $webhookHandler,
            $this->createStub(QueueDriverInterface::class),
        );

        self::assertSame('cms-webhooks', $job->queue());
        self::assertSame(1, $job->maxAttempts());
    }

    private function makeOrderService(ConnectionInterface $db): OrderService
    {
        return new OrderService(
            orders: $this->orders,
            invoiceService: $this->createStub(InvoiceServiceInterface::class),
            digitalDelivery: $this->createStub(DigitalDeliveryServiceInterface::class),
            db: $db,
            events: $this->createStub(EventDispatcherInterface::class),
        );
    }

    /**
     * Build a WebhookHandler without a queue driver (for retry job tests).
     */
    private function makeHandler(OrderService $orderService): WebhookHandler
    {
        return new WebhookHandler(
            orderService: $orderService,
            orders: $this->orders,
            paymentGateway: $this->paymentGateway,
            connection: $this->webhookConnection,
        );
    }

    /**
     * @param array<string, mixed> $objectData
     */
    private function makePayload(string $type, array $objectData = []): string
    {
        return json_encode([
            'id' => 'evt_' . uniqid(),
            'created' => time(),
            'type' => $type,
            'data' => ['object' => $objectData],
        ], JSON_THROW_ON_ERROR);
    }

    private function makePendingOrder(string $id): Order
    {
        $now = new DateTimeImmutable();

        return new Order(
            id: $id,
            tenantId: null,
            orderNumber: 'ORD-001',
            customerId: 'cust-1',
            customerEmail: 'test@example.com',
            status: OrderStatus::PendingPayment,
            subtotal: 10000,
            taxAmount: 0,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: 10000,
            amountRefunded: 0,
            currency: 'USD',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '123 Main St', 'city' => 'Anytown', 'postalCode' => '12345', 'country' => 'US'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
