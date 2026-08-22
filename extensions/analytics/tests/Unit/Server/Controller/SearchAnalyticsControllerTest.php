<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\SearchAnalyticsServiceInterface;
use Pulsar\Extension\Analytics\Domain\SearchQuery;
use Pulsar\Extension\Analytics\Server\Controller\SearchAnalyticsController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SearchAnalyticsControllerTest extends TestCase
{
    private SearchAnalyticsController $controller;
    private SearchAnalyticsServiceInterface&Stub $searchService;

    protected function setUp(): void
    {
        $this->searchService = $this->createStub(SearchAnalyticsServiceInterface::class);
        $this->controller = new SearchAnalyticsController($this->searchService);
    }

    #[Test]
    public function overviewReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/search/overview');

        $response = $this->controller->overview($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function overviewReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/overview',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->overview($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function overviewReturnsSearchMetrics(): void
    {
        $this->searchService->method('getOverview')->willReturn([
            'total_searches' => 500,
            'unique_queries' => 120,
            'zero_result_rate' => 8.5,
            'avg_click_through_rate' => 45.2,
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/overview',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->overview($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(500, $body['total_searches']);
        self::assertSame(120, $body['unique_queries']);
        self::assertSame(8.5, $body['zero_result_rate']);
    }

    #[Test]
    public function overviewUsesDefaultDateRange(): void
    {
        $this->searchService->method('getOverview')->willReturn([
            'total_searches' => 0,
            'unique_queries' => 0,
            'zero_result_rate' => 0.0,
            'avg_click_through_rate' => 0.0,
        ]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/overview',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->overview($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function topQueriesReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/search/queries');

        $response = $this->controller->topQueries($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function topQueriesReturnsFormattedQueries(): void
    {
        $queries = [
            new SearchQuery('pulsar framework', 45, 12, 68.5),
            new SearchQuery('php analytics', 30, 8, 55.0),
        ];
        $this->searchService->method('getTopQueries')->willReturn($queries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/queries',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->topQueries($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('pulsar framework', $body['data'][0]['query']);
        self::assertSame(45, $body['data'][0]['count']);
        self::assertSame(12, $body['data'][0]['result_count']);
        self::assertSame(68.5, $body['data'][0]['click_through_rate']);
    }

    #[Test]
    public function topQueriesReturnsEmptyForNoData(): void
    {
        $this->searchService->method('getTopQueries')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/queries',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->topQueries($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function zeroResultsReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/plsr/api/v1/search/zero-results');

        $response = $this->controller->zeroResults($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function zeroResultsReturnsZeroResultQueries(): void
    {
        $queries = [
            new SearchQuery('nonexistent feature', 20, 0),
            new SearchQuery('old product', 15, 0),
        ];
        $this->searchService->method('getZeroResultQueries')->willReturn($queries);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/zero-results',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->zeroResults($request);

        self::assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('nonexistent feature', $body['data'][0]['query']);
        self::assertSame(20, $body['data'][0]['count']);
    }

    #[Test]
    public function zeroResultsUsesDefaultDateRange(): void
    {
        $this->searchService->method('getZeroResultQueries')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/plsr/api/v1/search/zero-results',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->zeroResults($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
