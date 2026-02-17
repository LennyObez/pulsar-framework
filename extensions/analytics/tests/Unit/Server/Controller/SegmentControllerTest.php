<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SegmentServiceInterface;
use Pulsar\Extension\Analytics\Domain\Segment;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Server\Controller\SegmentController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SegmentControllerTest extends TestCase
{
    private SegmentController $controller;
    private SegmentServiceInterface&Stub $segmentService;

    protected function setUp(): void
    {
        $this->segmentService = $this->createStub(SegmentServiceInterface::class);
        $this->controller = new SegmentController($this->segmentService);
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/segments');

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/segments',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsSegmentsForSite(): void
    {
        $segments = [
            new Segment(
                id: 's-001',
                siteId: 'site-001',
                name: 'US Mobile',
                filters: [
                    new SegmentFilter(SegmentDimension::Country, SegmentOperator::Equals, 'US'),
                    new SegmentFilter(SegmentDimension::DeviceType, SegmentOperator::Equals, 'mobile'),
                ],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        ];
        $this->segmentService->method('listForSite')->willReturn($segments);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/segments',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['data']);
        self::assertSame('s-001', $body['data'][0]['id']);
        self::assertSame('US Mobile', $body['data'][0]['name']);
        self::assertCount(2, $body['data'][0]['filters']);
        self::assertSame('country', $body['data'][0]['filters'][0]['dimension']);
    }

    #[Test]
    public function createReturnsBadRequestWhenFieldsMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: ['site_id' => 'site-001'],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestWhenFiltersEmpty(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: ['site_id' => 'site-001', 'name' => 'Test', 'filters' => []],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestForInvalidFilter(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test',
                'filters' => [
                    ['dimension' => 'invalid', 'operator' => 'eq', 'value' => 'US'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturnsBadRequestForFilterMissingValue(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test',
                'filters' => [
                    ['dimension' => 'country', 'operator' => 'eq', 'value' => ''],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $this->segmentService->method('create')->willReturn(
            new Segment(
                id: 's-new',
                siteId: 'site-001',
                name: 'Chrome Users',
                filters: [new SegmentFilter(SegmentDimension::Browser, SegmentOperator::Equals, 'Chrome')],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Chrome Users',
                'filters' => [
                    ['dimension' => 'browser', 'operator' => 'eq', 'value' => 'Chrome'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-new', $body['id']);
        self::assertSame('Chrome Users', $body['name']);
    }

    #[Test]
    public function createSkipsNonArrayFilters(): void
    {
        $this->segmentService->method('create')->willReturn(
            new Segment(
                id: 's-new',
                siteId: 'site-001',
                name: 'Test',
                filters: [new SegmentFilter(SegmentDimension::Country, SegmentOperator::Equals, 'US')],
                createdAt: new DateTimeImmutable('2025-06-01'),
            ),
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/plsr/api/v1/segments',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test',
                'filters' => [
                    'not-an-array',
                    ['dimension' => 'country', 'operator' => 'eq', 'value' => 'US'],
                ],
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function countReturnsVisitorCount(): void
    {
        $this->segmentService->method('countVisitors')->willReturn(42);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/segments/s-001/count',
            queryParams: ['from' => '2025-01-01', 'to' => '2025-01-31'],
        );

        $response = $this->controller->count($request, 's-001');

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-001', $body['segment_id']);
        self::assertSame(42, $body['visitors']);
    }

    #[Test]
    public function countReturns404WhenSegmentNotFound(): void
    {
        $this->segmentService->method('countVisitors')->willThrowException(
            AnalyticsException::notFound('Segment', 'missing'),
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/segments/missing/count',
        );

        $response = $this->controller->count($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function countUsesDefaultDateRange(): void
    {
        $this->segmentService->method('countVisitors')->willReturn(10);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/segments/s-001/count',
        );

        $response = $this->controller->count($request, 's-001');

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/plsr/api/v1/segments/s-001',
        );

        $response = $this->controller->delete($request, 's-001');

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('s-001', $body['id']);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function deleteReturns404WhenSegmentNotFound(): void
    {
        $this->segmentService->method('delete')->willThrowException(
            AnalyticsException::notFound('Segment', 'missing'),
        );

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/plsr/api/v1/segments/missing',
        );

        $response = $this->controller->delete($request, 'missing');

        self::assertSame(404, $response->getStatusCode());
    }
}
