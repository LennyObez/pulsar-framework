<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\NoticeAction;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Dsa\ContentModeration\ModerationDecision;
use Pulsar\Extension\Dsa\ContentModeration\ModerationLog;
use Pulsar\Extension\Dsa\TrustedFlagger\TrustedFlaggerRegistry;

/**
 * Notice-and-action mechanism per DSA Article 16.
 *
 * Hosting services must implement a mechanism allowing any person
 * to notify them of illegal content. Notices must contain sufficient
 * information for the provider to make an informed and diligent
 * assessment. Trusted flagger notices receive priority processing.
 */
#[Api(since: '1.0.0')]
final readonly class NoticeAndActionHandler
{
    public function __construct(
        private ModerationLog $moderationLog,
        private TrustedFlaggerRegistry $trustedFlaggerRegistry,
    ) {}

    /**
     * Process an illegal content notice.
     *
     * Validates the notice, determines whether it originates from a
     * trusted flagger for priority handling, and returns an acknowledgment
     * with the processing status.
     *
     * @return array{
     *     notice_id: string,
     *     status: string,
     *     priority: bool,
     *     acknowledged_at: string
     * }
     */
    #[NoDiscard]
    public function processNotice(
        IllegalContentNotice $notice,
        DateTimeImmutable $acknowledgedAt,
    ): array {
        $isPriority = $notice->submitterId !== null
            && $this->trustedFlaggerRegistry->isTrusted($notice->submitterId);

        return [
            'notice_id' => $notice->id,
            'status' => 'acknowledged',
            'priority' => $isPriority,
            'acknowledged_at' => $acknowledgedAt->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * Act on a notice by recording a moderation decision.
     *
     * Per Art. 16(6), the provider must inform the notifying party of
     * its decision and provide information about redress possibilities.
     */
    public function actOnNotice(
        IllegalContentNotice $notice,
        ModerationDecision $decision,
    ): void {
        $this->moderationLog->record($decision);
    }

    /**
     * Dismiss a notice as not warranting action.
     *
     * Per Art. 16(6), the provider must inform the notifying party
     * with reasons for dismissal and available redress mechanisms.
     *
     * @return array{notice_id: string, status: string, dismissed_at: string, reason: string}
     */
    #[NoDiscard]
    public function dismiss(
        IllegalContentNotice $notice,
        string $reason,
        DateTimeImmutable $dismissedAt,
    ): array {
        return [
            'notice_id' => $notice->id,
            'status' => 'dismissed',
            'dismissed_at' => $dismissedAt->format('Y-m-d\TH:i:sP'),
            'reason' => $reason,
        ];
    }
}
