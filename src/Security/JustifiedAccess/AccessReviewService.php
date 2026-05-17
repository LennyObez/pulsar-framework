<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Service for compliance officers to review access justifications.
 *
 * Provides querying, filtering, and status management for justification
 * records. Supports the mandatory access review workflows required by
 * PCI-DSS, HIPAA, GDPR, and SOC 2.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AccessReviewService
{
    public function __construct(
        private JustificationStoreInterface $store,
        private AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Get justification records pending review.
     *
     * @return list<JustificationRecord>
     */
    public function pendingReviews(int $limit = 50): array
    {
        return $this->store->findByReviewStatus(ReviewStatus::Pending, $limit);
    }

    /**
     * Get flagged justification records requiring investigation.
     *
     * @return list<JustificationRecord>
     */
    public function flaggedRecords(int $limit = 50): array
    {
        return $this->store->findByReviewStatus(ReviewStatus::Flagged, $limit);
    }

    /**
     * Get all records for a specific actor.
     *
     * @return list<JustificationRecord>
     */
    public function recordsByActor(string $actorId, int $limit = 50): array
    {
        return $this->store->findByActor($actorId, $limit);
    }

    /**
     * Get all records for a specific resource.
     *
     * @return list<JustificationRecord>
     */
    public function recordsByResource(string $resourceType, string $resourceId, int $limit = 50): array
    {
        return $this->store->findByResource($resourceType, $resourceId, $limit);
    }

    /**
     * Get records within a date range.
     *
     * @return list<JustificationRecord>
     */
    public function recordsByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 100): array
    {
        return $this->store->findByDateRange($from, $to, $limit);
    }

    /**
     * Mark a justification record as reviewed.
     */
    public function markReviewed(string $recordId, string $reviewerActorId): void
    {
        $this->updateStatus($recordId, ReviewStatus::Reviewed, $reviewerActorId);
    }

    /**
     * Approve a justification record.
     */
    public function approve(string $recordId, string $reviewerActorId): void
    {
        $this->updateStatus($recordId, ReviewStatus::Approved, $reviewerActorId);
    }

    /**
     * Flag a justification record for investigation.
     */
    public function flag(string $recordId, string $reviewerActorId): void
    {
        $this->updateStatus($recordId, ReviewStatus::Flagged, $reviewerActorId);
    }

    /**
     * Escalate a justification record to senior management.
     */
    public function escalate(string $recordId, string $reviewerActorId): void
    {
        $this->updateStatus($recordId, ReviewStatus::Escalated, $reviewerActorId);
    }

    /**
     * Generate a summary for compliance reporting.
     *
     * @return array{total: int, pending: int, approved: int, flagged: int, reviewed: int, escalated: int, break_the_glass: int}
     */
    public function generateSummary(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $records = $this->store->findByDateRange($from, $to, 10000);

        $summary = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'flagged' => 0,
            'reviewed' => 0,
            'escalated' => 0,
            'break_the_glass' => 0,
        ];

        foreach ($records as $record) {
            ++$summary['total'];
            ++$summary[$record->reviewStatus->value];

            if ($record->breakTheGlass) {
                ++$summary['break_the_glass'];
            }
        }

        return $summary;
    }

    private function updateStatus(string $recordId, ReviewStatus $status, string $reviewerActorId): void
    {
        $existing = $this->store->find($recordId);

        if ($existing === null) {
            return;
        }

        $this->store->updateReviewStatus($recordId, $status);

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: $reviewerActorId,
            action: 'justified_access.review_updated',
            resource: $existing->resourceType . ':' . $existing->resourceId,
            metadata: [
                'justification_id' => $recordId,
                'previous_status' => $existing->reviewStatus->value,
                'new_status' => $status->value,
                'original_actor' => $existing->actorId,
            ],
        );
    }
}
