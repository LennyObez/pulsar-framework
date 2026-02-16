<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Http\Message\Response;

use function array_filter;
use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Public-facing search controller.
 *
 * Handles search queries from the frontend, returns ranked results
 * with suggestions for zero-result queries.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class SearchController
{
    public function __construct(
        private SearchServiceInterface $searchService,
    ) {}

    public function search(ServerRequestInterface $request, string $locale): Response
    {
        $params = $request->getQueryParams();

        $query = trim(is_string($params['q'] ?? null) ? $params['q'] : '');

        if ($query === '') {
            return Response::json([
                'data' => [],
                'query' => '',
                'total' => 0,
                'suggestions' => [],
                'took_ms' => 0.0,
            ]);
        }

        $contentType = is_string($params['type'] ?? null) ? $params['type'] : null;

        /** @var array<string, list<string>>|null $taxonomyFilters */
        $taxonomyFilters = null;

        if (is_array($params['taxonomy'] ?? null)) {
            $taxonomyFilters = [];

            /** @var array<string, mixed> $rawTaxonomy */
            $rawTaxonomy = $params['taxonomy'];

            foreach ($rawTaxonomy as $vocabulary => $terms) {
                if (is_string($vocabulary) && is_array($terms)) {
                    /** @var list<string> $stringTerms */
                    $stringTerms = array_filter($terms, 'is_string');
                    $taxonomyFilters[$vocabulary] = $stringTerms;
                }
            }
        }

        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 20));

        $result = $this->searchService->search(
            query: $query,
            locale: $locale,
            contentType: $contentType,
            taxonomyFilters: $taxonomyFilters,
            page: $page,
            perPage: $perPage,
        );

        return Response::json([
            'data' => array_map(static fn($content) => [
                'id' => $content->id,
                'type' => $content->contentType->value,
                'status' => $content->status->value,
                'author_id' => $content->authorId,
                'published_at' => $content->publishedAt?->format('c'),
                'updated_at' => $content->updatedAt->format('c'),
            ], $result->items),
            'query' => $result->query,
            'total' => $result->total,
            'suggestions' => $result->suggestions,
            'took_ms' => $result->tookMs,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function suggest(ServerRequestInterface $request, string $locale): Response
    {
        $params = $request->getQueryParams();
        $query = trim(is_string($params['q'] ?? null) ? $params['q'] : '');
        $limit = min(20, max(1, is_int($params['limit'] ?? null) ? $params['limit'] : 5));

        $suggestions = $this->searchService->suggest($query, $locale, $limit);

        return Response::json(['suggestions' => $suggestions]);
    }
}
