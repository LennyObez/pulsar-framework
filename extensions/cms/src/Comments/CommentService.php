<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Comments;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Comment service implementation handling submission, moderation, and editing.
 *
 * Sanitizes comment bodies through CommentBodyPolicy, validates state transitions,
 * and emits audit events for all moderation actions.
 */
#[Internal(reason: 'Use CommentServiceInterface for public API')]
final readonly class CommentService implements CommentServiceInterface
{
    public function __construct(
        private CommentRepositoryInterface $commentRepository,
        private ContentRepositoryInterface $contentRepository,
        private CommentBodyPolicy $bodyPolicy,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function submit(
        string $contentId,
        string $body,
        ?string $authorId,
        ?string $guestName,
        ?string $guestEmail,
        string $ipHash,
        string $userAgentHash,
    ): Comment {
        $content = $this->contentRepository->findById($contentId);

        if ($content === null) {
            throw CmsException::contentNotFound($contentId);
        }

        $sanitizedBody = $this->bodyPolicy->sanitize($body);

        if ($authorId !== null) {
            $comment = Comment::createAuthenticated(
                id: UuidGenerator::v7(),
                contentId: $contentId,
                authorId: $authorId,
                body: $sanitizedBody,
                ipHash: $ipHash,
                userAgentHash: $userAgentHash,
            );
        } else {
            $comment = Comment::create(
                id: UuidGenerator::v7(),
                contentId: $contentId,
                body: $sanitizedBody,
                ipHash: $ipHash,
                userAgentHash: $userAgentHash,
                guestName: $guestName,
                guestEmail: $guestEmail,
            );
        }

        $this->commentRepository->save($comment);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $authorId,
            'cms.comment.submitted',
            "comment:$comment->id",
            ['content_id' => $contentId, 'status' => $comment->status->value],
        );

        return $comment;
    }

    public function approve(string $commentId, string $moderatorId, string $reason): Comment
    {
        return $this->moderateComment($commentId, ModerationStatus::Approved, $moderatorId, $reason);
    }

    public function reject(string $commentId, string $moderatorId, string $reason): Comment
    {
        return $this->moderateComment($commentId, ModerationStatus::Rejected, $moderatorId, $reason);
    }

    public function markSpam(string $commentId, string $moderatorId, string $reason): Comment
    {
        return $this->moderateComment($commentId, ModerationStatus::Spam, $moderatorId, $reason);
    }

    public function edit(string $commentId, string $newBody): Comment
    {
        $comment = $this->commentRepository->findById($commentId);

        if ($comment === null) {
            throw CmsException::commentNotFound($commentId);
        }

        $sanitizedBody = $this->bodyPolicy->sanitize($newBody);
        $updated = $comment->edit($sanitizedBody);

        $this->commentRepository->save($updated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $comment->authorId,
            'cms.comment.edited',
            "comment:$commentId",
            ['content_id' => $comment->contentId],
        );

        return $updated;
    }

    private function moderateComment(
        string $commentId,
        ModerationStatus $target,
        string $moderatorId,
        string $reason,
    ): Comment {
        $comment = $this->commentRepository->findById($commentId);

        if ($comment === null) {
            throw CmsException::commentNotFound($commentId);
        }

        $moderated = $comment->moderate($target);

        $this->commentRepository->save($moderated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $moderatorId,
            "cms.comment.$target->value",
            "comment:$commentId",
            [
                'content_id' => $comment->contentId,
                'from_status' => $comment->status->value,
                'to_status' => $target->value,
                'reason' => $reason,
            ],
        );

        return $moderated;
    }
}
