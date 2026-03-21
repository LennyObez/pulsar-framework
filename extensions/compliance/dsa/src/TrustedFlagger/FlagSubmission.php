<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\TrustedFlagger;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * DTO representing a submission from a trusted flagger per DSA Article 22.
 *
 * Trusted flagger submissions include a reference to the content,
 * an explanation of why it is considered illegal, and the identity
 * of the trusted flagger. These submissions must be processed with
 * priority and without undue delay.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FlagSubmission
{
    /**
     * @param string            $id              Unique submission identifier
     * @param string            $flaggerId       Trusted flagger identifier
     * @param string            $contentId       Identifier of the flagged content
     * @param string            $contentType     Type of content (e.g., post, comment, media)
     * @param string            $reason          Explanation of illegality
     * @param string            $contentUrl      URL where the content can be accessed
     * @param DateTimeImmutable $submittedAt     When the submission was made
     * @param string            $status          Processing status: pending, reviewed, actioned, dismissed
     * @param string|null       $legalProvision  Specific legal provision the content violates
     */
    public function __construct(
        public string $id,
        public string $flaggerId,
        public string $contentId,
        public string $contentType,
        public string $reason,
        public string $contentUrl,
        public DateTimeImmutable $submittedAt,
        public string $status = 'pending',
        public ?string $legalProvision = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'flagger_id' => $this->flaggerId,
            'content_id' => $this->contentId,
            'content_type' => $this->contentType,
            'reason' => $this->reason,
            'content_url' => $this->contentUrl,
            'submitted_at' => $this->submittedAt->format('Y-m-d\TH:i:sP'),
            'status' => $this->status,
        ];

        if ($this->legalProvision !== null) {
            $data['legal_provision'] = $this->legalProvision;
        }

        return $data;
    }
}
