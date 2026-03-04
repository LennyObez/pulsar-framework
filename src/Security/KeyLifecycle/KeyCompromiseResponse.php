<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;

use function sprintf;

/**
 * Emergency response handler for key compromise events.
 *
 * Performs immediate key rotation without grace period, reports
 * a security incident, and creates a full audit trail of the
 * compromise response.
 *
 * Addresses NIST SP 800-57 key compromise procedures and
 * PCI-DSS Req 3.6.5 (retirement of compromised keys).
 */
#[Api(since: '1.0.0')]
final readonly class KeyCompromiseResponse
{
    public function __construct(
        private KeyRotationExecutor $executor,
        private KeyInventory $inventory,
        private IncidentReporterInterface $incidentReporter,
        private ?AuditLogger $auditLogger = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Respond to a key compromise.
     *
     * 1. Immediately rotates the compromised key (no grace period)
     * 2. Reports a security incident
     * 3. Emits audit events for compliance
     *
     * @param string $kid The compromised key identifier
     * @param string $reason Description of how the compromise was detected
     * @param array<string, mixed> $evidence Additional evidence/context
     */
    public function respond(string $kid, string $reason, array $evidence = []): CompromiseResponseResult
    {
        $entry = $this->inventory->find($kid);

        if ($entry === null) {
            $this->logger?->error(sprintf('Compromise response failed: key %s not found in inventory', $kid));

            return CompromiseResponseResult::failed($kid, 'Key not found in inventory');
        }

        $this->logger?->critical(sprintf(
            'KEY COMPROMISE DETECTED: %s (type: %s): initiating emergency rotation',
            $kid,
            $entry->type->value,
        ));

        // Immediately deactivate the compromised key
        $deactivatedEntry = new KeyInventoryEntry(
            kid: $entry->kid,
            type: $entry->type,
            createdAt: $entry->createdAt,
            lastRotatedAt: $entry->lastRotatedAt,
            rotationIntervalSeconds: $entry->rotationIntervalSeconds,
            active: false,
            algorithm: $entry->algorithm,
            context: $entry->context,
        );
        $this->inventory->register($deactivatedEntry);

        // Perform emergency rotation
        $rotationResult = $this->executor->rotate($deactivatedEntry, 'compromise');

        // Report security incident
        $incident = $this->incidentReporter->report(
            severity: IncidentSeverity::Critical,
            title: sprintf('Key compromise: %s (%s)', $kid, $entry->type->value),
            description: sprintf(
                'Compromised key %s (type: %s) was emergency-rotated. Reason: %s',
                $kid,
                $entry->type->value,
                $reason,
            ),
            source: 'KeyCompromiseResponse',
            metadata: [
                'compromised_kid' => $kid,
                'key_type' => $entry->type->value,
                'new_kid' => $rotationResult->kid,
                'reason' => $reason,
                'evidence' => $evidence,
            ],
        );

        // Emit audit event
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'key.compromise_response',
            resource: sprintf('key:%s', $kid),
            metadata: [
                'compromised_kid' => $kid,
                'new_kid' => $rotationResult->kid,
                'key_type' => $entry->type->value,
                'reason' => $reason,
                'incident_id' => $incident->id(),
            ],
        );

        $this->logger?->critical(sprintf(
            'Compromise response complete: %s → %s (incident: %s)',
            $kid,
            $rotationResult->kid,
            $incident->id(),
        ));

        return CompromiseResponseResult::success(
            compromisedKid: $kid,
            newKid: $rotationResult->kid,
            incidentId: $incident->id(),
            keyType: $entry->type,
        );
    }
}
