<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use Override;
use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Extension\Cms\Http\Controller\SearchController;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\SearchAnalytics;
use Pulsar\Extension\Cms\Search\SearchResult;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\CmsBenchmarkFactory;

/**
 * Search performance benchmark.
 *
 * Measures SearchController response construction with in-memory search service.
 * Isolates controller + serialization overhead from actual query execution.
 * Target: p95 < 200 microseconds per request (controller layer only).
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(2)]
final class SearchPerformanceBench
{
    private SearchController $controller;
    private ServerRequest $request;

    public function setUp(): void
    {
        $factory = new CmsBenchmarkFactory();
        $searchResult = $factory->createSearchResult(itemCount: 20);

        $searchService = new class ($searchResult) implements SearchServiceInterface {
            public function __construct(private readonly SearchResult $result) {}

            #[Override]
            public function search(
                string $query,
                string $locale,
                ?string $contentType = null,
                ?array $taxonomyFilters = null,
                int $page = 1,
                int $perPage = 20,
            ): SearchResult {
                return $this->result;
            }

            #[Override]
            public function suggest(string $partialQuery, string $locale, int $limit = 5): array
            {
                return ['suggestion 1', 'suggestion 2', 'suggestion 3'];
            }

            #[Override]
            public function recordClick(string $queryHash, string $contentId): void {}

            #[Override]
            public function getAnalytics(DateRange $range, ?string $tenantId = null): SearchAnalytics
            {
                return new SearchAnalytics(
                    topQueries: [],
                    zeroResultQueries: [],
                    clickThroughRates: [],
                    totalSearches: 1000,
                    uniqueQueries: 500,
                );
            }
        };

        $this->controller = new SearchController($searchService);

        $this->request = new ServerRequest(method: 'GET', uri: '/search?q=benchmark+test')
            ->withQueryParams(['q' => 'benchmark test', 'page' => '1', 'per_page' => '20']);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchSearchQuery(): void
    {
        $response = $this->controller->search($this->request, 'en');
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchSearchSuggest(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/search/suggest?q=bench')
            ->withQueryParams(['q' => 'bench', 'limit' => '5']);

        $response = $this->controller->suggest($request, 'en');
    }
}
