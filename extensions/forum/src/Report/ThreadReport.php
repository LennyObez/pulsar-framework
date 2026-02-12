<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Exception\ForumException;

/**
 * A user's report on a thread for moderation review.
 */
#[Api(since: '1.0.0')]
final readonly class ThreadReport
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $threadId UUIDv7 FK thread
     * @param string $reporterId UUIDv7 FK user who filed the report
     * @param string $reason User-provided reason for the report
     * @param ReportStatus $status Current report lifecycle status
     * @param string|null $moderatorId UUIDv7 FK moderator who reviewed
     * @param string|null $moderatorNote Moderator's resolution note
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable|null $reviewedAt When the report was reviewed
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $threadId,
        public string $reporterId,
        public string $reason,
        public ReportStatus $status,
        public ?string $moderatorId,
        public ?string $moderatorNote,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $reviewedAt,
    ) {}

    /**
     * File a new report against a thread.
     */
    public static function create(
        string $id,
        string $threadId,
        string $reporterId,
        string $reason,
        ?string $tenantId = null,
    ): self {
        return new self(
            id: $id,
            tenantId: $tenantId,
            threadId: $threadId,
            reporterId: $reporterId,
            reason: $reason,
            status: ReportStatus::Pending,
            moderatorId: null,
            moderatorNote: null,
            createdAt: new DateTimeImmutable(),
            reviewedAt: null,
        );
    }

    /**
     * Review this report — transition to the given status with moderator details.
     *
     * @throws ForumException If the transition is invalid
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function review(
        ReportStatus $target,
        string $moderatorId,
        string $moderatorNote = '',
    ): self {
        if (!$this->status->canTransitionTo($target)) {
            throw ForumException::invalidTransition($this->status->value, $target->value);
        }

        return clone($this, [
            'status' => $target,
            'moderatorId' => $moderatorId,
            'moderatorNote' => $moderatorNote,
            'reviewedAt' => new DateTimeImmutable(),
        ]);
    }
}
