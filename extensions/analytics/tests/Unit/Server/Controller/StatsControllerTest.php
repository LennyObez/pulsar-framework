<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Server\Controller\StatsController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class StatsControllerTest extends TestCase
{
    private StatsController $controller;
    private StatsServiceInterface&Stub $statsService;

    protected function setUp(): void
    {
        $this->statsService = $this->createStub(StatsServiceInterface::class);
        $this->controller = new StatsController($this->statsService);
    }

    #[Test]
    public function aggregateReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/stats');

        $response = $this->controller->aggregate($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function aggregateReturnsBadRequestWhenSiteIdIsNonString(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: ['site_id' => ['array']],
        );

        $response = $this->controller->aggregate($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function aggregateReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->aggregate($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function aggregateReturnsStatsWithDefaultDateRange(): void
    {
        $expectedData = [
            'visitors' => 150,
            'pageviews' => 400,
            'sessions' => 180,
            'bounce_rate' => 45.2,
            'avg_duration' => 120.5,
            'events_count' => 50,
        ];
        $this->statsService->method('getAggregate')->willReturn($expectedData);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->aggregate($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(150, $body['visitors']);
        self::assertSame(400, $body['pageviews']);
    }

    #[Test]
    public function aggregatePassesCustomDateRange(): void
    {
        $expectedData = [
            'visitors' => 10,
            'pageviews' => 20,
            'sessions' => 12,
            'bounce_rate' => 30.0,
            'avg_duration' => 60.0,
            'events_count' => 5,
        ];

        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getAggregate')
            ->with(
                'site-001',
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-01-01'),
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-01-31'),
                [],
            )
            ->willReturn($expectedData);

        $controller = new StatsController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: [
                'site_id' => 'site-001',
                'from' => '2025-01-01',
                'to' => '2025-01-31',
            ],
        );

        $response = $controller->aggregate($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aggregatePassesFiltersFromQueryParams(): void
    {
        $expectedData = [
            'visitors' => 5,
            'pageviews' => 8,
            'sessions' => 5,
            'bounce_rate' => 20.0,
            'avg_duration' => 90.0,
            'events_count' => 2,
        ];

        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getAggregate')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                ['page' => '/about'],
            )
            ->willReturn($expectedData);

        $controller = new StatsController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: [
                'site_id' => 'site-001',
                'filters' => ['page' => '/about'],
            ],
        );

        $response = $controller->aggregate($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aggregateIgnoresNonArrayFilters(): void
    {
        $expectedData = [
            'visitors' => 5,
            'pageviews' => 8,
            'sessions' => 5,
            'bounce_rate' => 20.0,
            'avg_duration' => 90.0,
            'events_count' => 2,
        ];

        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getAggregate')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                [],
            )
            ->willReturn($expectedData);

        $controller = new StatsController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/stats',
            queryParams: [
                'site_id' => 'site-001',
                'filters' => 'not-an-array',
            ],
        );

        $response = $controller->aggregate($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
