<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for documentation page feedback.
 */
#[Api(since: '1.0.0')]
interface DocFeedbackRepositoryInterface
{
    public function save(DocFeedback $feedback): void;

    /**
     * Retrieve paginated feedback entries for a given doc page.
     *
     * @return PaginationResult<DocFeedback>
     */
    public function findByDocPage(string $docPageId, int $page = 1, int $perPage = 20): PaginationResult;

    /**
     * Count helpful vs. not-helpful feedback for a given doc page.
     *
     * @return array{helpful: int, not_helpful: int}
     */
    public function countByDocPage(string $docPageId): array;
}
