<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Server\Controller\TimeseriesController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class TimeseriesControllerTest extends TestCase
{
    private TimeseriesController $controller;
    private StatsServiceInterface&Stub $statsService;

    protected function setUp(): void
    {
        $this->statsService = $this->createStub(StatsServiceInterface::class);
        $this->controller = new TimeseriesController($this->statsService);
    }

    #[Test]
    public function timeseriesReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/timeseries');

        $response = $this->controller->timeseries($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function timeseriesReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function timeseriesReturnsDataPoints(): void
    {
        $timeseries = [
            ['date' => '2025-03-01', 'value' => 100],
            ['date' => '2025-03-02', 'value' => 150],
            ['date' => '2025-03-03', 'value' => 120],
        ];
        $this->statsService->method('getTimeseries')->willReturn($timeseries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(3, $body['data']);
        self::assertSame('2025-03-01', $body['data'][0]['date']);
        self::assertSame(100, $body['data'][0]['value']);
    }

    #[Test]
    public function timeseriesDefaultsToVisitorsMetricAndDayInterval(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getTimeseries')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                'visitors',
                'day',
            )
            ->willReturn([]);

        $controller = new TimeseriesController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: ['site_id' => 'site-001'],
        );

        $controller->timeseries($request);
    }

    #[Test]
    public function timeseriesPassesCustomMetricAndInterval(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getTimeseries')
            ->with(
                'site-001',
                self::isInstanceOf(DateTimeImmutable::class),
                self::isInstanceOf(DateTimeImmutable::class),
                'pageviews',
                'hour',
            )
            ->willReturn([]);

        $controller = new TimeseriesController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: [
                'site_id' => 'site-001',
                'metric' => 'pageviews',
                'interval' => 'hour',
            ],
        );

        $controller->timeseries($request);
    }

    #[Test]
    public function timeseriesPassesCustomDateRange(): void
    {
        $service = $this->createMock(StatsServiceInterface::class);
        $service->expects(self::once())
            ->method('getTimeseries')
            ->with(
                'site-001',
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-01-01'),
                self::callback(static fn(DateTimeImmutable $d) => $d->format('Y-m-d') === '2025-01-31'),
                'visitors',
                'day',
            )
            ->willReturn([]);

        $controller = new TimeseriesController($service);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: [
                'site_id' => 'site-001',
                'from' => '2025-01-01',
                'to' => '2025-01-31',
            ],
        );

        $controller->timeseries($request);
    }

    #[Test]
    public function timeseriesReturnsBadRequestWhenSiteIdIsNonString(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: ['site_id' => ['array']],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function timeseriesReturnsEmptyDataArray(): void
    {
        $this->statsService->method('getTimeseries')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/timeseries',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }
}
