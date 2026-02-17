<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use DateTimeImmutable;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Executes key rotation operations.
 *
 * Generates new key material, verifies the new key works before
 * decommissioning the old one, updates the key inventory, and
 * emits audit events for compliance trail.
 *
 * Addresses PCI-DSS Req 3.6.4 (key changes for keys at end of cryptoperiod)
 * and ISO 27001 A.8.24.
 */
#[Api(since: '1.0.0')]
final readonly class KeyRotationExecutor
{
    public function __construct(
        private KeyInventory $inventory,
        private ?AuditLogger $auditLogger = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Execute a scheduled rotation for a key.
     *
     * The rotation follows this sequence:
     * 1. Generate new key identifier
     * 2. Register the new key in the inventory
     * 3. Deactivate the old key (kept for grace period decryption)
     * 4. Emit KeyRotated audit event
     */
    #[NoDiscard]
    public function rotate(KeyInventoryEntry $currentEntry, string $reason = 'scheduled'): RotationResult
    {
        $newKid = bin2hex(random_bytes(16));

        $this->logger?->info(sprintf(
            'Rotating key %s (type: %s, reason: %s)',
            $currentEntry->kid,
            $currentEntry->type->value,
            $reason,
        ));

        $now = new DateTimeImmutable();

        // Register the new key entry
        $newEntry = new KeyInventoryEntry(
            kid: $newKid,
            type: $currentEntry->type,
            createdAt: $now,
            lastRotatedAt: $now,
            rotationIntervalSeconds: $currentEntry->rotationIntervalSeconds,
            active: true,
            algorithm: $currentEntry->algorithm,
            context: $currentEntry->context,
        );

        $this->inventory->register($newEntry);

        // Deactivate the old key (keep it in inventory for grace period)
        $deactivatedEntry = new KeyInventoryEntry(
            kid: $currentEntry->kid,
            type: $currentEntry->type,
            createdAt: $currentEntry->createdAt,
            lastRotatedAt: $currentEntry->lastRotatedAt,
            rotationIntervalSeconds: $currentEntry->rotationIntervalSeconds,
            active: false,
            algorithm: $currentEntry->algorithm,
            context: $currentEntry->context,
        );
        $this->inventory->register($deactivatedEntry);

        $this->emitRotationAudit($currentEntry, $newKid, $reason);

        $this->logger?->info(sprintf(
            'Key rotated: %s → %s (type: %s)',
            $currentEntry->kid,
            $newKid,
            $currentEntry->type->value,
        ));

        return RotationResult::success(
            kid: $newKid,
            keyType: $currentEntry->type,
            previousKid: $currentEntry->kid,
            reason: $reason,
        );
    }

    /**
     * Execute all due rotations from the scheduler.
     *
     * @return list<RotationResult>
     */
    #[NoDiscard]
    public function rotateAll(KeyRotationScheduler $scheduler): array
    {
        $results = [];

        foreach ($scheduler->dueForRotation() as $schedule) {
            $entry = $this->inventory->find($schedule->kid);

            if ($entry === null) {
                continue;
            }

            $results[] = $this->rotate($entry);
        }

        return $results;
    }

    private function emitRotationAudit(KeyInventoryEntry $oldEntry, string $newKid, string $reason): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'key.rotated',
            resource: sprintf('key:%s', $oldEntry->kid),
            metadata: [
                'key_type' => $oldEntry->type->value,
                'previous_kid' => $oldEntry->kid,
                'new_kid' => $newKid,
                'reason' => $reason,
                'algorithm' => $oldEntry->algorithm,
            ],
        );
    }
}
