<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a right-to-be-forgotten erasure operation.
 *
 * Contains the details of the deleted pseudonym mapping including the
 * audit trail entry identifier for regulatory evidence. This record
 * supports controls for GDPR Article 17 right-to-erasure compliance.
 */
#[Api(since: '1.0.0')]
final readonly class ForgetResult
{
    public function __construct(
        public string $subjectId,
        public string $pseudonym,
        public DateTimeImmutable $forgottenAt,
        public string $auditEntryId,
    ) {}

    /**
     * @return array<string, string>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'pseudonym' => $this->pseudonym,
            'forgotten_at' => $this->forgottenAt->format('Y-m-d\TH:i:s.uP'),
            'audit_entry_id' => $this->auditEntryId,
        ];
    }
}
