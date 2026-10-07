<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookHandler;

use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies that WebhookHandler wraps business logic and event recording
 * in the same database transaction for atomicity using the connection's
 * transaction() method.
 */
#[CoversClass(WebhookHandler::class)]
final class WebhookHandlerAtomicityTest extends TestCase
{
    #[Test]
    public function handleUsesTransactionForAtomicProcessing(): void
    {
        $transactionCalled = false;

        /** @var ConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ConnectionInterface::class);

        // The transaction() method should be called exactly once
        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(static function (callable $callback) use (&$transactionCalled): mixed {
                $transactionCalled = true;
                // Execute the callback to simulate the transaction
                return $callback();
            });

        // Stub the duplicate check query to return no results
        $result = new Result([]);
        $connection->method('query')->willReturn($result);
        $connection->method('execute')->willReturn(0);

        $gateway = $this->createStub(PaymentGateway::class);
        $gateway->method('verifyWebhookSignature')->willReturn(true);

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);

        $orderService = new OrderService(
            orders: $orders,
            invoiceService: $this->createStub(InvoiceServiceInterface::class),
            digitalDelivery: $this->createStub(DigitalDeliveryServiceInterface::class),
            db: $connection,
            events: $events,
        );

        $handler = new WebhookHandler(
            orderService: $orderService,
            orders: $orders,
            paymentGateway: $gateway,
            connection: $connection,
        );

        $payload = json_encode([
            'id' => 'evt_test_123',
            'created' => time(),
            'type' => 'unknown.event',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);

        $handler->handle($payload, 'valid-signature');

        self::assertTrue($transactionCalled, 'connection->transaction() was called for atomicity');
    }
}
