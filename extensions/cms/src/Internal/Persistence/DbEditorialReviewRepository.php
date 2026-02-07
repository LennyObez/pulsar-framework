<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

#[Internal(reason: 'Raw-DB repository — use EditorialWorkflowServiceInterface for public API')]
final readonly class DbEditorialReviewRepository
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_editorial_reviews WHERE id = :id
        SQL;

    private const string SQL_FIND_PENDING = <<<'SQL'
        SELECT * FROM cms_editorial_reviews
        WHERE status IN ('pending', 'in_review')
        ORDER BY created_at ASC
        SQL;

    private const string SQL_FIND_PENDING_BY_REVIEWER = <<<'SQL'
        SELECT * FROM cms_editorial_reviews
        WHERE status IN ('pending', 'in_review')
          AND (reviewer_id = :reviewer_id OR reviewer_id IS NULL)
        ORDER BY created_at ASC
        SQL;

    private const string SQL_FIND_BY_CONTENT = <<<'SQL'
        SELECT * FROM cms_editorial_reviews
        WHERE content_id = :content_id
        ORDER BY created_at DESC
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_editorial_reviews (
            id, content_id, locale, requested_by, reviewer_id,
            status, comment, decision_reason, created_at, decided_at
        ) VALUES (
            :id, :content_id, :locale, :requested_by, :reviewer_id,
            :status, :comment, :decision_reason, :created_at, :decided_at
        )
        ON CONFLICT (id) DO UPDATE SET
            reviewer_id = EXCLUDED.reviewer_id,
            status = EXCLUDED.status,
            comment = EXCLUDED.comment,
            decision_reason = EXCLUDED.decision_reason,
            decided_at = EXCLUDED.decided_at
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?EditorialReview
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * @return list<EditorialReview>
     */
    public function findPending(?string $reviewerId = null): array
    {
        if ($reviewerId !== null) {
            $result = $this->connection->query(self::SQL_FIND_PENDING_BY_REVIEWER, [
                'reviewer_id' => $reviewerId,
            ]);
        } else {
            $result = $this->connection->query(self::SQL_FIND_PENDING);
        }

        return $result->map(self::hydrate(...));
    }

    /**
     * @return list<EditorialReview>
     */
    public function findByContent(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT, [
            'content_id' => $contentId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(EditorialReview $review): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $review->id,
            'content_id' => $review->contentId,
            'locale' => $review->locale,
            'requested_by' => $review->requestedBy,
            'reviewer_id' => $review->reviewerId,
            'status' => $review->status->value,
            'comment' => $review->comment,
            'decision_reason' => $review->decisionReason,
            'created_at' => $review->createdAt->format('c'),
            'decided_at' => $review->decidedAt?->format('c'),
        ]);
    }

    private static function hydrate(Row $row): EditorialReview
    {
        $decidedAtRaw = $row->getNullableString('decided_at');

        return new EditorialReview(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            locale: $row->getNullableString('locale'),
            requestedBy: $row->getString('requested_by'),
            reviewerId: $row->getNullableString('reviewer_id'),
            status: ReviewStatus::from($row->getString('status')),
            comment: $row->getNullableString('comment'),
            decisionReason: $row->getNullableString('decision_reason'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            decidedAt: $decidedAtRaw !== null ? new DateTimeImmutable($decidedAtRaw) : null,
        );
    }
}
