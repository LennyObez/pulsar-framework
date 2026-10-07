<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\NoticeAction;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * DTO for illegal content notices per DSA Article 16.
 *
 * A notice must contain sufficient information to enable an informed
 * assessment of the alleged illegality, including: an explanation of
 * the reasons, a clear indication of the electronic location, and
 * the name and email of the notifying party (Art. 16(2)).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IllegalContentNotice
{
    /**
     * @param string            $id               Unique notice identifier
     * @param string            $contentId         Identifier of the allegedly illegal content
     * @param string            $contentUrl        URL of the allegedly illegal content
     * @param string            $reason            Explanation of why the content is illegal
     * @param string            $submitterName     Name of the notifying party
     * @param string            $submitterEmail    Email of the notifying party
     * @param DateTimeImmutable $submittedAt       When the notice was submitted
     * @param string|null       $submitterId       User ID of the submitter (null for anonymous)
     * @param string|null       $legalProvision    Specific law or regulation the content violates
     * @param string|null       $territory         EU member state where the content is illegal
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $contentUrl,
        public string $reason,
        public string $submitterName,
        public string $submitterEmail,
        public DateTimeImmutable $submittedAt,
        public ?string $submitterId = null,
        public ?string $legalProvision = null,
        public ?string $territory = null,
    ) {}

    /**
     * Whether the notice includes a specific legal provision reference.
     */
    #[NoDiscard]
    public function hasLegalProvision(): bool
    {
        return $this->legalProvision !== null;
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
            'content_url' => $this->contentUrl,
            'reason' => $this->reason,
            'submitter_name' => $this->submitterName,
            'submitter_email' => $this->submitterEmail,
            'submitted_at' => $this->submittedAt->format('Y-m-d\TH:i:sP'),
        ];

        if ($this->submitterId !== null) {
            $data['submitter_id'] = $this->submitterId;
        }

        if ($this->legalProvision !== null) {
            $data['legal_provision'] = $this->legalProvision;
        }

        if ($this->territory !== null) {
            $data['territory'] = $this->territory;
        }

        return $data;
    }
}
