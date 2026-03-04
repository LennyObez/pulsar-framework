<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\ContentModeration;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable DTO representing a content moderation decision per DSA Article 17.
 *
 * Every moderation decision must include a clear statement of reasons
 * (Art. 17(3)) covering: the facts, the applicable rule, and an explanation
 * of how the content violates that rule. Users must be informed of available
 * redress mechanisms.
 */
#[Api(since: '1.0.0')]
final readonly class ModerationDecision
{
    /**
     * @param string            $id           Unique decision identifier
     * @param string            $contentId    Identifier of the moderated content
     * @param string            $contentType  Type of content (e.g., post, comment, media)
     * @param string            $decision     Action taken: remove, restrict, label, demote, suspend
     * @param string            $reason       Statement of reasons per Art. 17(3)
     * @param string            $policyId     The moderation policy that triggered this decision
     * @param string            $detectionMethod How the content was detected: automated, human, trusted_flagger, notice
     * @param DateTimeImmutable $decidedAt    When the decision was made
     * @param string            $appealUrl    URL where the user can submit an appeal
     * @param string|null       $moderatorId  Identifier of the moderator (null for automated decisions)
     * @param string|null       $territory    EU member state jurisdiction if applicable
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $contentType,
        public string $decision,
        public string $reason,
        public string $policyId,
        public string $detectionMethod,
        public DateTimeImmutable $decidedAt,
        public string $appealUrl,
        public ?string $moderatorId = null,
        public ?string $territory = null,
    ) {}

    /**
     * Whether this decision was made by automated means (Art. 14(6)).
     */
    #[NoDiscard]
    public function isAutomated(): bool
    {
        return $this->detectionMethod === 'automated';
    }

    /**
     * Whether this decision originated from a trusted flagger (Art. 22).
     */
    #[NoDiscard]
    public function isFromTrustedFlagger(): bool
    {
        return $this->detectionMethod === 'trusted_flagger';
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'content_id' => $this->contentId,
            'content_type' => $this->contentType,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'policy_id' => $this->policyId,
            'detection_method' => $this->detectionMethod,
            'decided_at' => $this->decidedAt->format('Y-m-d\TH:i:sP'),
            'appeal_url' => $this->appealUrl,
        ];

        if ($this->moderatorId !== null) {
            $data['moderator_id'] = $this->moderatorId;
        }

        if ($this->territory !== null) {
            $data['territory'] = $this->territory;
        }

        return $data;
    }
}
