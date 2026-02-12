<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use DateTimeImmutable;
use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Service\ForumSearchServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;
use function max;
use function min;
use function trim;

/**
 * Enhanced search API controller for forum full-text search.
 *
 * Supports query parameters: q, category, author, tag, solved, from, to,
 * page, per_page. Delegates to ForumSearchServiceInterface for
 * driver-specific full-text search.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class ForumSearchController
{
    public function __construct(
        private ForumSearchServiceInterface $searchService,
        private ForumConfig $config,
    ) {}

    /**
     * GET /api/v1/forum/search — Full-text search with filters.
     */
    public function search(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        $query = is_string($params['q'] ?? null) ? trim($params['q']) : '';

        if ($query === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['q' => 'Search query is required'],
            ], 422);
        }

        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        $categoryId = is_string($params['category'] ?? null) && $params['category'] !== ''
            ? $params['category']
            : null;

        $authorId = is_string($params['author'] ?? null) && $params['author'] !== ''
            ? $params['author']
            : null;

        $tag = is_string($params['tag'] ?? null) && $params['tag'] !== ''
            ? $params['tag']
            : null;

        $solved = null;

        if (isset($params['solved'])) {
            $solvedParam = $params['solved'];

            if ($solvedParam === 'true' || $solvedParam === '1') {
                $solved = true;
            } elseif ($solvedParam === 'false' || $solvedParam === '0') {
                $solved = false;
            }
        }

        $from = self::parseDate($params['from'] ?? null);
        $to = self::parseDate($params['to'] ?? null);

        $result = $this->searchService->search(
            query: $query,
            categoryId: $categoryId,
            authorId: $authorId,
            tag: $tag,
            solved: $solved,
            from: $from,
            to: $to,
            page: $page,
            perPage: $perPage,
        );

        return Response::json([
            'data' => $result->items,
            'pagination' => $result->metaToArray(),
            'query' => $query,
            'filters' => [
                'category' => $categoryId,
                'author' => $authorId,
                'tag' => $tag,
                'solved' => $solved,
                'from' => $from?->format('c'),
                'to' => $to?->format('c'),
            ],
        ]);
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
