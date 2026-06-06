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

use function is_int;
use function is_numeric;
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
#[Internal(reason: 'Forum REST API controller; implementation detail')]
final readonly class ForumSearchController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ForumSearchServiceInterface $searchService,
        private ForumConfig $config,
    ) {}

    /**
     * GET /api/v1/forum/search: Full-text search with filters.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function search(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var mixed $rawQuery */
        $rawQuery = $params['q'] ?? null;
        $query = is_string($rawQuery) ? trim($rawQuery) : '';

        if ($query === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['q' => 'Search query is required'],
            ], 422);
        }

        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, (is_int($rawPage) || is_string($rawPage)) && is_numeric($rawPage) ? (int) $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, (is_int($rawPerPage) || is_string($rawPerPage)) && is_numeric($rawPerPage) ? (int) $rawPerPage : $this->config->threadsPerPage));

        /** @var mixed $rawCategory */
        $rawCategory = $params['category'] ?? null;
        $categoryId = is_string($rawCategory) && $rawCategory !== '' ? $rawCategory : null;

        /** @var mixed $rawAuthor */
        $rawAuthor = $params['author'] ?? null;
        $authorId = is_string($rawAuthor) && $rawAuthor !== '' ? $rawAuthor : null;

        /** @var mixed $rawTag */
        $rawTag = $params['tag'] ?? null;
        $tag = is_string($rawTag) && $rawTag !== '' ? $rawTag : null;

        $solved = null;

        /** @var mixed $solvedParam */
        $solvedParam = $params['solved'] ?? null;
        if ($solvedParam !== null) {
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
