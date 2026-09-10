<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Auth\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\Auth\WebAuthn\Contract\AuthenticatorRepositoryInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;

/**
 * In-memory authenticator repository for testing and development.
 *
 * Not suitable for production use: records are lost on process termination.
 * Production applications should provide a persistent implementation
 * (database-backed) and bind it to AuthenticatorRepositoryInterface.
 *
 * Every mutation is written to the tamper-evident audit chain before the
 * in-memory state changes, because the mutations are exactly the events an
 * assessor asks for: adding, renaming and — above all — removing a second
 * factor. Reads are not audited; `listByUserId()` and `countActive()` run on
 * every account-settings render and auditing them would bury the mutations that
 * matter under noise, with no control asking for them.
 */
#[Internal(reason: 'Default in-memory adapter for development')]
final class InMemoryAuthenticatorRepository implements AuthenticatorRepositoryInterface
{
    /** @var array<string, AuthenticatorRecord> keyed by credentialId */
    private array $records = [];

    /**
     * The audit logger is required, not optional. An authenticator store that
     * can silently drop a user's second factor is the control gap, not a
     * degraded mode: SOC 2 CC6.1/CC7.2, ISO/IEC 27001 A.8.15 and HIPAA
     * §164.312(b) all read "removal of an authentication factor" as an event
     * that must leave a record. Constructing the repository without a logger is
     * therefore impossible rather than merely discouraged.
     */
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function findByCredentialId(string $credentialId): ?AuthenticatorRecord
    {
        return $this->records[$credentialId] ?? null;
    }

    public function listByUserId(string $userId): array
    {
        $result = [];

        foreach ($this->records as $record) {
            if ($record->userId === $userId) {
                $result[] = $record;
            }
        }

        return $result;
    }

    public function register(AuthenticatorRecord $record): void
    {
        $this->auditLogger->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: AuditActor::user($record->userId),
            action: 'webauthn.authenticator.registered',
            resource: 'webauthn:authenticator:' . $record->credentialId,
            metadata: [
                'credential_id' => $record->credentialId,
                'display_name' => $record->displayName,
                'authenticator_type' => $record->type->value,
                'aaguid' => $record->aaguid,
                'replaced_existing' => isset($this->records[$record->credentialId]),
            ],
        );

        $this->records[$record->credentialId] = $record;
    }

    public function rename(string $credentialId, string $newName): void
    {
        $existing = $this->records[$credentialId] ?? null;

        if ($existing === null) {
            $this->logUnknownCredential($credentialId, 'webauthn.authenticator.renamed');

            return;
        }

        $this->auditLogger->log(
            event: AuditEvent::ConfigurationChange,
            outcome: AuditOutcome::Success,
            actor: AuditActor::user($existing->userId),
            action: 'webauthn.authenticator.renamed',
            resource: 'webauthn:authenticator:' . $credentialId,
            metadata: [
                'credential_id' => $credentialId,
                'previous_display_name' => $existing->displayName,
                'new_display_name' => $newName,
            ],
        );

        $this->records[$credentialId] = new AuthenticatorRecord(
            credentialId: $existing->credentialId,
            userId: $existing->userId,
            displayName: $newName,
            type: $existing->type,
            aaguid: $existing->aaguid,
            active: $existing->active,
            registeredAt: $existing->registeredAt,
            lastUsedAt: $existing->lastUsedAt,
        );
    }

    public function revoke(string $credentialId): void
    {
        $existing = $this->records[$credentialId] ?? null;

        if ($existing === null) {
            $this->logUnknownCredential($credentialId, 'webauthn.authenticator.revoked');

            return;
        }

        // Recorded as a SecurityEvent rather than a plain configuration change:
        // stripping a second factor is the step that precedes an account
        // takeover, and it is the line a detection rule watches for.
        // `remaining_active` is carried because "the user's last factor was
        // removed" is a materially different event from "one of four was".
        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::user($existing->userId),
            action: 'webauthn.authenticator.revoked',
            resource: 'webauthn:authenticator:' . $credentialId,
            metadata: [
                'credential_id' => $credentialId,
                'display_name' => $existing->displayName,
                'authenticator_type' => $existing->type->value,
                'was_active' => $existing->active,
                'remaining_active' => $this->countActive($existing->userId) - ($existing->active ? 1 : 0),
            ],
        );

        $this->records[$credentialId] = new AuthenticatorRecord(
            credentialId: $existing->credentialId,
            userId: $existing->userId,
            displayName: $existing->displayName,
            type: $existing->type,
            aaguid: $existing->aaguid,
            active: false,
            registeredAt: $existing->registeredAt,
            lastUsedAt: $existing->lastUsedAt,
        );
    }

    public function countActive(string $userId): int
    {
        $count = 0;

        foreach ($this->records as $record) {
            if ($record->userId === $userId && $record->active) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Record a mutation aimed at a credential this store does not hold.
     *
     * The call is a no-op for the caller, but it is not a non-event: a rename
     * or revocation addressed to an unknown credential id is either a stale
     * client or someone probing the identifier space, and dropping it would
     * leave the probe invisible. No user can be named, so the entry is
     * attributed to an anonymous actor and the outcome is a failure.
     */
    private function logUnknownCredential(string $credentialId, string $action): void
    {
        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Failure,
            actor: AuditActor::anonymous(),
            action: $action,
            resource: 'webauthn:authenticator:' . $credentialId,
            metadata: [
                'credential_id' => $credentialId,
                'reason' => 'credential_not_registered',
                'known_credentials' => count($this->records),
            ],
        );
    }
}
