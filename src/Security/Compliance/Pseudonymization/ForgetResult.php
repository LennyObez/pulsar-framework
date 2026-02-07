<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a right-to-be-forgotten erasure operation.
 *
 * Contains a one-way confirmation hash (SHA-256 of the deleted pseudonym)
 * and the audit entry identifier. Neither the original subject ID nor
 * the pseudonym are retained, preventing re-linkage after erasure.
 *
 * This design supports controls for GDPR Article 17 right-to-erasure
 * by avoiding retention of identifiers that could enable re-linkage.
 */
#[Api(since: '1.0.0')]
final readonly class ForgetResult
{
    public function __construct(
        public string $confirmationHash,
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
            'confirmation_hash' => $this->confirmationHash,
            'forgotten_at' => $this->forgottenAt->format('Y-m-d\TH:i:s.uP'),
            'audit_entry_id' => $this->auditEntryId,
        ];
    }
}
