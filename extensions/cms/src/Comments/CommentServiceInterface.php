<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Service interface for comment operations.
 *
 * @psalm-api Public binding contract; implemented by CommentService and
 *            consumed by public-comment + admin moderation controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface CommentServiceInterface
{
    /**
     * Submit a new comment on a content item.
     *
     * @throws CmsException If the content does not exist
     */
    public function submit(
        string $contentId,
        string $body,
        ?string $authorId,
        ?string $guestName,
        ?string $guestEmail,
        string $ipHash,
        string $userAgentHash,
    ): Comment;

    /**
     * Approve a pending comment.
     *
     * @throws CmsException If the comment is not found or transition is invalid
     */
    public function approve(string $commentId, string $moderatorId, string $reason): Comment;

    /**
     * Reject a pending comment.
     *
     * @throws CmsException If the comment is not found or transition is invalid
     */
    public function reject(string $commentId, string $moderatorId, string $reason): Comment;

    /**
     * Mark a pending comment as spam.
     *
     * @throws CmsException If the comment is not found or transition is invalid
     */
    public function markSpam(string $commentId, string $moderatorId, string $reason): Comment;

    /**
     * Edit an existing comment within its edit window.
     *
     * @throws CmsException If the comment is not found or edit window expired
     */
    public function edit(string $commentId, string $newBody): Comment;
}
