<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for the Comment entity.
 *
 * @psalm-api Public binding contract; implemented by DbCommentRepository and
 *            consumed by CommentService and admin moderation controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface CommentRepositoryInterface
{
    public function findById(string $id): ?Comment;

    /**
     * @return PaginationResult<Comment>
     */
    public function findByContent(
        string $contentId,
        ?ModerationStatus $status = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    /**
     * @return PaginationResult<Comment>
     */
    public function findPendingModeration(
        ?string $tenantId = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult;

    public function save(Comment $comment): void;

    public function delete(Comment $comment): void;
}
