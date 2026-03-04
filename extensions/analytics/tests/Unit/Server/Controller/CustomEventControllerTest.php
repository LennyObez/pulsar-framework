<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\CustomEventServiceInterface;
use Pulsar\Extension\Analytics\Server\Controller\CustomEventController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class CustomEventControllerTest extends TestCase
{
    private CustomEventController $controller;
    private CustomEventServiceInterface&Stub $customEventService;

    protected function setUp(): void
    {
        $this->customEventService = $this->createStub(CustomEventServiceInterface::class);
        $this->controller = new CustomEventController($this->customEventService);
    }

    #[Test]
    public function namesReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/events/names');

        $response = $this->controller->names($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function namesReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/names',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->names($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function namesReturnsEventNames(): void
    {
        $this->customEventService->method('getEventNames')->willReturn([
            ['event_name' => 'click_cta', 'count' => 100, 'visitors' => 80],
            ['event_name' => 'signup', 'count' => 50, 'visitors' => 45],
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/names',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->names($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('click_cta', $body['data'][0]['event_name']);
        self::assertSame(100, $body['data'][0]['count']);
    }

    #[Test]
    public function namesReturnsEmptyForNoEvents(): void
    {
        $this->customEventService->method('getEventNames')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/names',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->names($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function propertiesReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/properties',
            queryParams: ['event_name' => 'signup'],
        );

        $response = $this->controller->properties($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function propertiesReturnsBadRequestWhenEventNameMissing(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/properties',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->properties($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function propertiesReturnsBadRequestWhenBothMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/events/properties');

        $response = $this->controller->properties($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function propertiesReturnsEventProperties(): void
    {
        $this->customEventService->method('getEventProperties')->willReturn([
            ['property' => 'plan', 'value' => 'pro', 'count' => 30],
            ['property' => 'plan', 'value' => 'free', 'count' => 20],
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/properties',
            queryParams: ['site_id' => 'site-001', 'event_name' => 'signup'],
        );

        $response = $this->controller->properties($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('plan', $body['data'][0]['property']);
        self::assertSame('pro', $body['data'][0]['value']);
    }

    #[Test]
    public function timeseriesReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/timeseries',
            queryParams: ['event_name' => 'signup'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function timeseriesReturnsBadRequestWhenEventNameMissing(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/timeseries',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function timeseriesReturnsEventTimeseries(): void
    {
        $this->customEventService->method('getEventTimeseries')->willReturn([
            ['date' => '2025-01-01', 'count' => 10],
            ['date' => '2025-01-02', 'count' => 15],
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/timeseries',
            queryParams: ['site_id' => 'site-001', 'event_name' => 'signup'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('2025-01-01', $body['data'][0]['date']);
        self::assertSame(10, $body['data'][0]['count']);
    }

    #[Test]
    public function timeseriesUsesDefaultDateRange(): void
    {
        $this->customEventService->method('getEventTimeseries')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/events/timeseries',
            queryParams: ['site_id' => 'site-001', 'event_name' => 'click'],
        );

        $response = $this->controller->timeseries($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
