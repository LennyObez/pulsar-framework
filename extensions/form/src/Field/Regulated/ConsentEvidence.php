<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Field\Regulated;

use Pulsar\Api\Api;

/**
 * Immutable evidence record for regulated consent capture.
 *
 * Captures all information needed to prove what the user consented to,
 * when, and in what context.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConsentEvidence
{
    public function __construct(
        public string $timestamp,
        public string $purpose,
        public string $policyVersion,
        public string $locale,
        public string $subject,
        public string $correlationId,
        public string $templateHash,
        public string $policyTextHash,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'purpose' => $this->purpose,
            'policy_version' => $this->policyVersion,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'correlation_id' => $this->correlationId,
            'template_hash' => $this->templateHash,
            'policy_text_hash' => $this->policyTextHash,
        ];
    }
}
