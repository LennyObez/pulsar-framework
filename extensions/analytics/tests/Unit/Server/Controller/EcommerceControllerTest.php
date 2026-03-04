<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\EcommerceServiceInterface;
use Pulsar\Extension\Analytics\Server\Controller\EcommerceController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class EcommerceControllerTest extends TestCase
{
    private EcommerceController $controller;
    private EcommerceServiceInterface&Stub $ecommerceService;

    protected function setUp(): void
    {
        $this->ecommerceService = $this->createStub(EcommerceServiceInterface::class);
        $this->controller = new EcommerceController($this->ecommerceService);
    }

    #[Test]
    public function summaryReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/ecommerce/summary');

        $response = $this->controller->summary($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function summaryReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/summary',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->summary($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function summaryReturnsSummaryMetrics(): void
    {
        $this->ecommerceService->method('getSummary')->willReturn([
            'revenue' => 5000.00,
            'transactions' => 100,
            'average_order_value' => 50.00,
            'conversion_rate' => 3.5,
            'items_sold' => 250,
            'currency' => 'USD',
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/summary',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->summary($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertEquals(5000.00, $body['revenue']);
        self::assertSame(100, $body['transactions']);
        self::assertEquals(50.00, $body['average_order_value']);
        self::assertSame('USD', $body['currency']);
    }

    #[Test]
    public function summaryUsesDefaultDateRange(): void
    {
        $this->ecommerceService->method('getSummary')->willReturn([
            'revenue' => 0.0,
            'transactions' => 0,
            'average_order_value' => 0.0,
            'conversion_rate' => 0.0,
            'items_sold' => 0,
            'currency' => 'USD',
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/summary',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->summary($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function productsReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/ecommerce/products');

        $response = $this->controller->products($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function productsReturnsTopProducts(): void
    {
        $products = [
            ['product_id' => 'p-001', 'name' => 'Widget', 'revenue' => 1200.00, 'quantity' => 40],
            ['product_id' => 'p-002', 'name' => 'Gadget', 'revenue' => 800.00, 'quantity' => 25],
        ];
        $this->ecommerceService->method('getTopProducts')->willReturn($products);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/products',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->products($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('Widget', $body['data'][0]['name']);
    }

    #[Test]
    public function productsPassesLimitParameter(): void
    {
        $service = $this->createMock(EcommerceServiceInterface::class);
        $service->expects(self::once())
            ->method('getTopProducts')
            ->with('site-001', self::anything(), self::anything(), 5)
            ->willReturn([]);

        $controller = new EcommerceController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/products',
            queryParams: ['site_id' => 'site-001', 'limit' => '5'],
        );

        $controller->products($request);
    }

    #[Test]
    public function productsClampLimitBetween1And100(): void
    {
        $service = $this->createMock(EcommerceServiceInterface::class);
        $service->expects(self::once())
            ->method('getTopProducts')
            ->with('site-001', self::anything(), self::anything(), 100)
            ->willReturn([]);

        $controller = new EcommerceController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/products',
            queryParams: ['site_id' => 'site-001', 'limit' => '999'],
        );

        $controller->products($request);
    }

    #[Test]
    public function revenueReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/ecommerce/revenue');

        $response = $this->controller->revenue($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function revenueReturnsTimeseriesData(): void
    {
        $data = [
            ['date' => '2025-01-01', 'revenue' => 500.00, 'transactions' => 10],
            ['date' => '2025-01-02', 'revenue' => 750.00, 'transactions' => 15],
        ];
        $this->ecommerceService->method('getRevenueTimeseries')->willReturn($data);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/ecommerce/revenue',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->revenue($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('2025-01-01', $body['data'][0]['date']);
        self::assertEquals(500.00, $body['data'][0]['revenue']);
    }
}
