<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\EcommerceItem;
use Pulsar\Extension\Analytics\Domain\EcommerceTransaction;
use Pulsar\Extension\Analytics\Internal\Service\EcommerceService;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class EcommerceServiceTest extends TestCase
{
    private EcommerceService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new EcommerceService($this->connection);
    }

    #[Test]
    public function recordTransactionExecutesInsert(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::anything(), self::callback(static fn(array $params): bool => $params['order_id'] === 'order-123' && $params['revenue'] === 99.99))
            ->willReturn(1);

        $service = new EcommerceService($connection);

        $transaction = new EcommerceTransaction(
            id: 'tx-001',
            siteId: 'site-001',
            visitorId: 'v-001',
            sessionId: 'sess-001',
            orderId: 'order-123',
            revenue: 99.99,
            tax: 8.50,
            shipping: 5.00,
            currency: 'USD',
            items: [new EcommerceItem('p-001', 'Widget', 'Electronics', 49.99, 2, '')],
            createdAt: new DateTimeImmutable(),
        );

        $service->recordTransaction($transaction);
    }

    #[Test]
    public function recordTransactionHandlesEmptyItems(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(self::anything(), self::callback(static fn(array $params): bool => $params['items'] === null))
            ->willReturn(1);

        $service = new EcommerceService($connection);

        $transaction = new EcommerceTransaction(
            id: 'tx-002',
            siteId: 'site-001',
            visitorId: 'v-001',
            sessionId: 'sess-001',
            orderId: 'order-456',
            revenue: 50.00,
            tax: 4.25,
            shipping: 0.00,
            currency: 'USD',
            items: [],
            createdAt: new DateTimeImmutable(),
        );

        $service->recordTransaction($transaction);
    }

    #[Test]
    public function getSummaryReturnsSummaryMetrics(): void
    {
        $summaryRow = new Row([
            'currency' => 'USD',
            'total_revenue' => 5000.00,
            'total_transactions' => 100,
            'avg_order_value' => 50.00,
        ]);
        $summaryResult = new Result([$summaryRow]);

        $sessionsRow = new Row(['cnt' => 2000]);
        $sessionsResult = new Result([$sessionsRow]);

        $itemsJson = json_encode([
            ['product_id' => 'p-001', 'name' => 'Widget', 'price' => 25.00, 'quantity' => 3],
        ], JSON_THROW_ON_ERROR);
        $itemRow = new Row(['items' => $itemsJson]);
        $itemsResult = new Result([$itemRow]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($summaryResult, $sessionsResult, $itemsResult);

        $result = $this->service->getSummary(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(5000.00, $result['revenue']);
        self::assertSame(100, $result['transactions']);
        self::assertSame(50.00, $result['average_order_value']);
        self::assertSame(5.0, $result['conversion_rate']);
        self::assertSame(3, $result['items_sold']);
        self::assertSame('USD', $result['currency']);
    }

    #[Test]
    public function getSummaryReturnsZerosForNoData(): void
    {
        $summaryRow = new Row([
            'currency' => 'USD',
            'total_revenue' => 0.0,
            'total_transactions' => 0,
            'avg_order_value' => 0.0,
        ]);
        $summaryResult = new Result([$summaryRow]);

        $sessionsRow = new Row(['cnt' => 0]);
        $sessionsResult = new Result([$sessionsRow]);

        $itemsResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($summaryResult, $sessionsResult, $itemsResult);

        $result = $this->service->getSummary(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(0.0, $result['revenue']);
        self::assertSame(0, $result['transactions']);
        self::assertSame(0.0, $result['average_order_value']);
        self::assertSame(0.0, $result['conversion_rate']);
        self::assertSame(0, $result['items_sold']);
    }

    #[Test]
    public function getSummaryHandlesNullItemsColumn(): void
    {
        $summaryRow = new Row([
            'currency' => 'USD',
            'total_revenue' => 100.00,
            'total_transactions' => 1,
            'avg_order_value' => 100.00,
        ]);
        $summaryResult = new Result([$summaryRow]);

        $sessionsRow = new Row(['cnt' => 10]);
        $sessionsResult = new Result([$sessionsRow]);

        $itemRow = new Row(['items' => null]);
        $itemsResult = new Result([$itemRow]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($summaryResult, $sessionsResult, $itemsResult);

        $result = $this->service->getSummary(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame(0, $result['items_sold']);
    }

    #[Test]
    public function getTopProductsReturnsProductsSortedByRevenue(): void
    {
        $itemsJson = json_encode([
            ['product_id' => 'p-001', 'name' => 'Widget', 'price' => 25.00, 'quantity' => 5],
            ['product_id' => 'p-002', 'name' => 'Gadget', 'price' => 100.00, 'quantity' => 2],
        ], JSON_THROW_ON_ERROR);

        $row = new Row(['items' => $itemsJson]);
        $queryResult = new Result([$row]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getTopProducts(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        // Gadget has higher revenue (200 vs 125)
        self::assertSame('Gadget', $result[0]['name']);
        self::assertSame(200.0, $result[0]['revenue']);
        self::assertSame('Widget', $result[1]['name']);
    }

    #[Test]
    public function getTopProductsRespectsLimit(): void
    {
        $itemsJson = json_encode([
            ['product_id' => 'p-001', 'name' => 'A', 'price' => 10.00, 'quantity' => 1],
            ['product_id' => 'p-002', 'name' => 'B', 'price' => 20.00, 'quantity' => 1],
            ['product_id' => 'p-003', 'name' => 'C', 'price' => 30.00, 'quantity' => 1],
        ], JSON_THROW_ON_ERROR);

        $row = new Row(['items' => $itemsJson]);
        $queryResult = new Result([$row]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getTopProducts(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            2,
        );

        self::assertCount(2, $result);
    }

    #[Test]
    public function getTopProductsSkipsNullItems(): void
    {
        $row = new Row(['items' => null]);
        $queryResult = new Result([$row]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getTopProducts(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getRevenueTimeseriesReturnsTimeseries(): void
    {
        $rows = [
            new Row(['date' => '2025-01-01', 'revenue' => 500.123, 'transactions' => 10]),
            new Row(['date' => '2025-01-02', 'revenue' => 750.456, 'transactions' => 15]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getRevenueTimeseries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        self::assertSame('2025-01-01', $result[0]['date']);
        self::assertSame(500.12, $result[0]['revenue']);
        self::assertSame(10, $result[0]['transactions']);
    }

    #[Test]
    public function getRevenueTimeseriesReturnsEmptyForNoData(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getRevenueTimeseries(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }
}
