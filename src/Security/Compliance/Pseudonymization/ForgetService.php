<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Compliance\Exception\ComplianceException;

use function hash;

/**
 * Right-to-be-forgotten service for pseudonym mapping erasure.
 *
 * Deletes the pseudonym mapping for a subject and emits an audit event
 * recording the erasure. The audit trail preserves evidence that the
 * deletion was performed without retaining the original mapping data.
 *
 * This implementation supports controls for GDPR Article 17
 * right-to-erasure requirements.
 */
#[Internal(reason: 'Forget service implementation')]
final class ForgetService implements ForgetServiceInterface
{
    public function __construct(
        private readonly PseudonymLookupInterface $lookup,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function forget(string $subjectId): ForgetResult
    {
        $mapping = $this->lookup->findBySubjectId($subjectId);

        if ($mapping === null) {
            throw ComplianceException::pseudonymNotFound();
        }

        $pseudonym = $mapping->pseudonym;

        $this->lookup->delete($subjectId);

        $confirmationHash = hash('sha256', $pseudonym);

        $auditEntry = $this->auditLogger->log(
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'pseudonym.forget',
            resource: $confirmationHash,
            metadata: ['confirmation_hash' => $confirmationHash],
        );

        return new ForgetResult(
            confirmationHash: $confirmationHash,
            forgottenAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            auditEntryId: $auditEntry->id,
        );
    }
}
