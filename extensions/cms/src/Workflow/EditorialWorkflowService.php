<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function sprintf;

/**
 * Manages the editorial review workflow lifecycle.
 *
 * Coordinates content status transitions with editorial review records,
 * audit logging, and reviewer assignment.
 */
#[Internal]
final readonly class EditorialWorkflowService implements EditorialWorkflowServiceInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private ContentRepositoryInterface $contentRepository,
        private AuditLoggerInterface $auditLogger,
    ) {}

    public function submitForReview(
        string $contentId,
        string $requestedBy,
        ?string $locale = null,
        ?string $reviewerId = null,
    ): EditorialReview {
        $content = $this->findContentOrFail($contentId);

        if (!$content->status->canTransitionTo(PublishingStatus::InReview, editorialWorkflow: true)) {
            throw CmsException::invalidTransition($content->status->value, PublishingStatus::InReview->value);
        }

        $now = new DateTimeImmutable();
        $reviewId = $this->generateUuidV7();

        $review = new EditorialReview(
            id: $reviewId,
            contentId: $contentId,
            locale: $locale,
            requestedBy: $requestedBy,
            reviewerId: $reviewerId,
            status: ReviewStatus::Pending,
            comment: null,
            decisionReason: null,
            createdAt: $now,
            decidedAt: null,
        );

        $this->db->execute(
            <<<'SQL'
                INSERT INTO cms_editorial_reviews (id, content_id, locale, requested_by, reviewer_id, status, comment, decision_reason, created_at, decided_at)
                VALUES (:id, :content_id, :locale, :requested_by, :reviewer_id, :status, :comment, :decision_reason, :created_at, :decided_at)
                SQL,
            [
                'id' => $review->id,
                'content_id' => $review->contentId,
                'locale' => $review->locale,
                'requested_by' => $review->requestedBy,
                'reviewer_id' => $review->reviewerId,
                'status' => $review->status->value,
                'comment' => $review->comment,
                'decision_reason' => $review->decisionReason,
                'created_at' => $review->createdAt->format('Y-m-d H:i:s'),
                'decided_at' => null,
            ],
        );

        // Transition content to InReview
        $updated = $content->submitForReview();
        $this->contentRepository->save($updated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $requestedBy,
            'cms.workflow.submitted_for_review',
            "content:{$contentId}",
            [
                'review_id' => $reviewId,
                'reviewer_id' => $reviewerId,
                'locale' => $locale,
            ],
        );

        return $review;
    }

    public function approve(string $reviewId, string $decisionReason): EditorialReview
    {
        $review = $this->findReviewOrFail($reviewId);
        $now = new DateTimeImmutable();

        $approved = new EditorialReview(
            id: $review->id,
            contentId: $review->contentId,
            locale: $review->locale,
            requestedBy: $review->requestedBy,
            reviewerId: $review->reviewerId,
            status: ReviewStatus::Approved,
            comment: $review->comment,
            decisionReason: $decisionReason,
            createdAt: $review->createdAt,
            decidedAt: $now,
        );

        $this->db->execute(
            'UPDATE cms_editorial_reviews SET status = :status, decision_reason = :decision_reason, decided_at = :decided_at WHERE id = :id',
            [
                'status' => ReviewStatus::Approved->value,
                'decision_reason' => $decisionReason,
                'decided_at' => $now->format('Y-m-d H:i:s'),
                'id' => $reviewId,
            ],
        );

        // Transition content to Approved
        $content = $this->findContentOrFail($review->contentId);
        $updated = $content->approve();
        $this->contentRepository->save($updated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $review->reviewerId,
            'cms.workflow.approved',
            "content:{$review->contentId}",
            [
                'review_id' => $reviewId,
                'decision_reason' => $decisionReason,
            ],
        );

        return $approved;
    }

    public function reject(string $reviewId, string $decisionReason, ?string $comment = null): EditorialReview
    {
        $review = $this->findReviewOrFail($reviewId);
        $now = new DateTimeImmutable();

        $rejected = new EditorialReview(
            id: $review->id,
            contentId: $review->contentId,
            locale: $review->locale,
            requestedBy: $review->requestedBy,
            reviewerId: $review->reviewerId,
            status: ReviewStatus::Rejected,
            comment: $comment,
            decisionReason: $decisionReason,
            createdAt: $review->createdAt,
            decidedAt: $now,
        );

        $this->db->execute(
            'UPDATE cms_editorial_reviews SET status = :status, decision_reason = :decision_reason, comment = :comment, decided_at = :decided_at WHERE id = :id',
            [
                'status' => ReviewStatus::Rejected->value,
                'decision_reason' => $decisionReason,
                'comment' => $comment,
                'decided_at' => $now->format('Y-m-d H:i:s'),
                'id' => $reviewId,
            ],
        );

        // Transition content back to Draft
        $content = $this->findContentOrFail($review->contentId);
        $updated = $content->reject();
        $this->contentRepository->save($updated);

        $this->auditLogger->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            $review->reviewerId,
            'cms.workflow.rejected',
            "content:{$review->contentId}",
            [
                'review_id' => $reviewId,
                'decision_reason' => $decisionReason,
                'comment' => $comment,
            ],
        );

        return $rejected;
    }

    public function getPendingReviews(?string $reviewerId = null): array
    {
        $sql = 'SELECT id, content_id, locale, requested_by, reviewer_id, status, comment, decision_reason, created_at, decided_at FROM cms_editorial_reviews WHERE status = :status';
        $bindings = ['status' => ReviewStatus::Pending->value];

        if ($reviewerId !== null) {
            $sql .= ' AND (reviewer_id = :reviewer_id OR reviewer_id IS NULL)';
            $bindings['reviewer_id'] = $reviewerId;
        }

        $sql .= ' ORDER BY created_at ASC';

        $result = $this->db->query($sql, $bindings);
        $reviews = [];

        foreach ($result->rows as $row) {
            $decidedAt = $row->get('decided_at');

            $reviews[] = new EditorialReview(
                id: (string) $row->get('id'),
                contentId: (string) $row->get('content_id'),
                locale: $row->get('locale') !== null ? (string) $row->get('locale') : null,
                requestedBy: (string) $row->get('requested_by'),
                reviewerId: $row->get('reviewer_id') !== null ? (string) $row->get('reviewer_id') : null,
                status: ReviewStatus::from((string) $row->get('status')),
                comment: $row->get('comment') !== null ? (string) $row->get('comment') : null,
                decisionReason: $row->get('decision_reason') !== null ? (string) $row->get('decision_reason') : null,
                createdAt: new DateTimeImmutable((string) $row->get('created_at')),
                decidedAt: $decidedAt !== null ? new DateTimeImmutable((string) $decidedAt) : null,
            );
        }

        return $reviews;
    }

    public function cancelReview(string $reviewId): EditorialReview
    {
        $review = $this->findReviewOrFail($reviewId);
        $now = new DateTimeImmutable();

        $cancelled = new EditorialReview(
            id: $review->id,
            contentId: $review->contentId,
            locale: $review->locale,
            requestedBy: $review->requestedBy,
            reviewerId: $review->reviewerId,
            status: ReviewStatus::Cancelled,
            comment: $review->comment,
            decisionReason: null,
            createdAt: $review->createdAt,
            decidedAt: $now,
        );

        $this->db->execute(
            'UPDATE cms_editorial_reviews SET status = :status, decided_at = :decided_at WHERE id = :id',
            [
                'status' => ReviewStatus::Cancelled->value,
                'decided_at' => $now->format('Y-m-d H:i:s'),
                'id' => $reviewId,
            ],
        );

        // Transition content back to Draft
        $content = $this->findContentOrFail($review->contentId);

        if ($content->status === PublishingStatus::InReview) {
            $updated = $content->reject();
            $this->contentRepository->save($updated);
        }

        return $cancelled;
    }

    private function findContentOrFail(string $contentId): Content
    {
        $content = $this->contentRepository->findById($contentId);

        if ($content === null) {
            throw CmsException::contentNotFound($contentId);
        }

        return $content;
    }

    private function findReviewOrFail(string $reviewId): EditorialReview
    {
        $row = $this->db->query(
            'SELECT id, content_id, locale, requested_by, reviewer_id, status, comment, decision_reason, created_at, decided_at FROM cms_editorial_reviews WHERE id = :id',
            ['id' => $reviewId],
        )->first();

        if ($row === null) {
            throw CmsException::contentNotFound($reviewId);
        }

        $decidedAt = $row->get('decided_at');

        return new EditorialReview(
            id: (string) $row->get('id'),
            contentId: (string) $row->get('content_id'),
            locale: $row->get('locale') !== null ? (string) $row->get('locale') : null,
            requestedBy: (string) $row->get('requested_by'),
            reviewerId: $row->get('reviewer_id') !== null ? (string) $row->get('reviewer_id') : null,
            status: ReviewStatus::from((string) $row->get('status')),
            comment: $row->get('comment') !== null ? (string) $row->get('comment') : null,
            decisionReason: $row->get('decision_reason') !== null ? (string) $row->get('decision_reason') : null,
            createdAt: new DateTimeImmutable((string) $row->get('created_at')),
            decidedAt: $decidedAt !== null ? new DateTimeImmutable((string) $decidedAt) : null,
        );
    }

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
