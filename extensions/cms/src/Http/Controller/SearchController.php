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
 *
 * @psalm-api Bound to a route by the CMS service provider; resolved
 *            from the DI container by the router.
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

        /** @var mixed $rawQuery */
        $rawQuery = $params['q'] ?? null;
        $query = trim(is_string($rawQuery) ? $rawQuery : '');

        if ($query === '') {
            return Response::json([
                'data' => [],
                'query' => '',
                'total' => 0,
                'suggestions' => [],
                'took_ms' => 0.0,
            ]);
        }

        /** @var mixed $rawType */
        $rawType = $params['type'] ?? null;
        $contentType = is_string($rawType) ? $rawType : null;

        /** @var array<string, list<string>>|null $taxonomyFilters */
        $taxonomyFilters = null;

        /** @var mixed $rawTaxonomyParam */
        $rawTaxonomyParam = $params['taxonomy'] ?? null;
        if (is_array($rawTaxonomyParam)) {
            $taxonomyFilters = [];

            /** @var mixed $terms */
            foreach ($rawTaxonomyParam as $vocabulary => $terms) {
                if (is_string($vocabulary) && is_array($terms)) {
                    /** @var list<string> $stringTerms */
                    $stringTerms = array_filter($terms, 'is_string');
                    $taxonomyFilters[$vocabulary] = $stringTerms;
                }
            }
        }

        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));

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
        /** @var mixed $rawQuery */
        $rawQuery = $params['q'] ?? null;
        $query = trim(is_string($rawQuery) ? $rawQuery : '');
        /** @var mixed $rawLimit */
        $rawLimit = $params['limit'] ?? null;
        $limit = min(20, max(1, is_int($rawLimit) ? $rawLimit : 5));

        $suggestions = $this->searchService->suggest($query, $locale, $limit);

        return Response::json(['suggestions' => $suggestions]);
    }
}
