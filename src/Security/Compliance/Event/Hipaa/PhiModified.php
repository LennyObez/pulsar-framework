<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

/**
 * Records modification of Protected Health Information (PHI).
 *
 * Supports controls for HIPAA Security Rule integrity controls.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class PhiModified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $phiCategories
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modifierIdentity,
        public string $patientPseudonym,
        public array $phiCategories,
        public string $modificationType,
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'phi_modified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'modifier_identity' => $this->modifierIdentity,
            'patient_pseudonym' => $this->patientPseudonym,
            'phi_categories' => $this->phiCategories,
            'modification_type' => $this->modificationType,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $phiCategories */
        $phiCategories = is_array($data['phi_categories'] ?? null) ? array_values($data['phi_categories']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            modifierIdentity: is_string($data['modifier_identity'] ?? null) ? $data['modifier_identity'] : '',
            patientPseudonym: is_string($data['patient_pseudonym'] ?? null) ? $data['patient_pseudonym'] : '',
            phiCategories: $phiCategories,
            modificationType: is_string($data['modification_type'] ?? null) ? $data['modification_type'] : '',
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
        );
    }
}
