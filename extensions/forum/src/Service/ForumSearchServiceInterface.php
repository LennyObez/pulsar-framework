<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Full-text search service for forum threads and posts.
 *
 * Supports driver-specific optimizations: PostgreSQL uses tsvector/tsquery,
 * MySQL uses FULLTEXT indexes, and SQLite falls back to LIKE matching.
 * @api
 */
#[Api(since: '1.0.0')]
interface ForumSearchServiceInterface
{
    /**
     * Search forum threads with full-text query and optional filters.
     *
     * @return PaginationResult<array<string, mixed>>
     */
    public function search(
        string $query,
        ?string $categoryId = null,
        ?string $authorId = null,
        ?string $tag = null,
        ?bool $solved = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;
}
