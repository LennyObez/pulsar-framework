<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\ContentModeration;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Internal complaint-handling mechanism per DSA Article 20.
 *
 * Platforms must provide users with an internal complaint system
 * that allows them to contest moderation decisions. Complaints
 * must be handled in a timely, non-discriminatory, non-arbitrary
 * manner by qualified staff.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AppealHandler
{
    public function __construct(
        private ModerationLog $moderationLog,
    ) {}

    /**
     * Submit an appeal against a moderation decision.
     *
     * Validates that the original decision exists and returns an appeal
     * record. The appeal must be reviewed by qualified staff (Art. 20(4)).
     *
     * @return array{
     *     appeal_id: string,
     *     decision_id: string,
     *     status: string,
     *     reason: string,
     *     submitted_by: string,
     *     submitted_at: string,
     *     original_decision: string
     * }|null Null if the referenced decision does not exist
     */
    #[NoDiscard]
    public function submit(
        string $appealId,
        string $decisionId,
        string $reason,
        string $submittedBy,
        DateTimeImmutable $submittedAt,
    ): ?array {
        $original = $this->moderationLog->findById($decisionId);

        if ($original === null) {
            return null;
        }

        // The grounds and the complainant are the substance of an appeal under
        // Art. 20(1): the record existed without either, so a submitted appeal could
        // not be reviewed on its merits or attributed to anyone.
        return [
            'appeal_id' => $appealId,
            'decision_id' => $decisionId,
            'status' => 'pending_review',
            'reason' => $reason,
            'submitted_by' => $submittedBy,
            'submitted_at' => $submittedAt->format('Y-m-d\TH:i:sP'),
            'original_decision' => $original->decision,
        ];
    }

    /**
     * Resolve an appeal by upholding or overturning the original decision.
     *
     * Per Art. 20(5), the outcome must be communicated to the complainant
     * along with information about out-of-court dispute settlement (Art. 21).
     *
     * @return array{
     *     appeal_id: string,
     *     outcome: string,
     *     resolved_at: string,
     *     reviewer_id: string
     * }
     */
    #[NoDiscard]
    public function resolve(
        string $appealId,
        string $outcome,
        string $reviewerId,
        DateTimeImmutable $resolvedAt,
    ): array {
        return [
            'appeal_id' => $appealId,
            'outcome' => $outcome,
            'resolved_at' => $resolvedAt->format('Y-m-d\TH:i:sP'),
            'reviewer_id' => $reviewerId,
        ];
    }
}
