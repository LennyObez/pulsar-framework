<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\SearchAnalyticsController;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SearchAnalyticsController::class)]
final class SearchAnalyticsControllerTest extends TestCase
{
    #[Test]
    public function index_returns_analytics_with_default_date_range(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [['query_text' => 'php', 'count' => 50, 'avg_results' => 12.5, 'ctr' => 0.35]],
            zeroResultQueries: [['query_text' => 'nonexistent', 'count' => 5, 'last_searched' => '2026-03-01']],
            clickThroughRates: [['query_text' => 'php', 'clicks' => 20, 'searches' => 50, 'ctr' => 0.4]],
            totalSearches: 100,
            uniqueQueries: 42,
        );

        $searchService = $this->createStub(SearchServiceInterface::class);
        $searchService->method('getAnalytics')->willReturn($analytics);

        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $stats */
        $stats = $body['stats'];
        self::assertSame(100, $stats['total_searches']);
        self::assertSame(42, $stats['unique_queries']);
        self::assertEquals(1.0, $stats['zero_result_rate']);
        self::assertSame('0.4', $stats['avg_ctr']);

        /** @var list<mixed> $topQueries */
        $topQueries = $body['topQueries'];
        /** @var list<mixed> $zeroResultQueries */
        $zeroResultQueries = $body['zeroResultQueries'];
        self::assertCount(1, $topQueries);
        self::assertCount(1, $zeroResultQueries);
    }

    #[Test]
    public function index_accepts_custom_date_range(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [],
            clickThroughRates: [],
            totalSearches: 0,
            uniqueQueries: 0,
        );

        $searchService = $this->createStub(SearchServiceInterface::class);
        $searchService->method('getAnalytics')->willReturn($analytics);

        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest(queryParams: [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ]);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, string> $filters */
        $filters = $body['filters'];
        self::assertSame('2026-01-01', $filters['date_from']);
        self::assertSame('2026-01-31', $filters['date_to']);
    }

    #[Test]
    public function index_returns_400_for_invalid_from_date(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest(queryParams: ['from' => 'not-a-date']);

        $response = $controller->index($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('"from"', (string) $body['error']);
    }

    #[Test]
    public function index_returns_400_for_invalid_to_date(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest(queryParams: ['to' => 'invalid']);

        $response = $controller->index($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('"to"', (string) $body['error']);
    }

    #[Test]
    public function index_returns_400_when_from_after_to(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest(queryParams: [
            'from' => '2026-06-01',
            'to' => '2026-01-01',
        ]);

        $response = $controller->index($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('before or equal', (string) $body['error']);
    }

    #[Test]
    public function index_returns_400_when_range_exceeds_366_days(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest(queryParams: [
            'from' => '2024-01-01',
            'to' => '2026-01-01',
        ]);

        $response = $controller->index($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('366', (string) $body['error']);
    }

    #[Test]
    public function index_computes_zero_result_rate_correctly(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [
                ['query_text' => 'q1', 'count' => 3, 'last_searched' => '2026-03-01'],
                ['query_text' => 'q2', 'count' => 2, 'last_searched' => '2026-03-01'],
            ],
            clickThroughRates: [],
            totalSearches: 10,
            uniqueQueries: 5,
        );

        $searchService = $this->createStub(SearchServiceInterface::class);
        $searchService->method('getAnalytics')->willReturn($analytics);

        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $stats */
        $stats = $body['stats'];
        self::assertEquals(20.0, $stats['zero_result_rate']);
    }

    #[Test]
    public function index_handles_zero_total_searches(): void
    {
        $analytics = new SearchAnalytics(
            topQueries: [],
            zeroResultQueries: [],
            clickThroughRates: [],
            totalSearches: 0,
            uniqueQueries: 0,
        );

        $searchService = $this->createStub(SearchServiceInterface::class);
        $searchService->method('getAnalytics')->willReturn($analytics);

        $controller = new SearchAnalyticsController(searchService: $searchService);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $stats */
        $stats = $body['stats'];
        self::assertEquals(0.0, $stats['zero_result_rate']);
        self::assertSame('0.0', $stats['avg_ctr']);
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $controller = new SearchAnalyticsController(searchService: $searchService);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $searchService = $this->createStub(SearchServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new SearchAnalyticsController(searchService: $searchService, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(array $queryParams = []): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/search-analytics');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/search-analytics');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
