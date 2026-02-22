<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

/**
 * Records detection of an ICT-related incident.
 *
 * Supports controls for DORA Article 17 ICT-related incident management.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class IctIncidentDetected extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $affectedSystems
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reporterIdentity,
        public string $incidentType,
        public string $severity,
        public array $affectedSystems,
        public string $description,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'dora';
    }

    public function eventType(): string
    {
        return 'ict_incident_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'incident_type' => $this->incidentType,
            'severity' => $this->severity,
            'affected_systems' => $this->affectedSystems,
            'description' => $this->description,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $affectedSystems */
        $affectedSystems = is_array($data['affected_systems'] ?? null) ? array_values($data['affected_systems']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            reporterIdentity: is_string($data['reporter_identity'] ?? null) ? $data['reporter_identity'] : '',
            incidentType: is_string($data['incident_type'] ?? null) ? $data['incident_type'] : '',
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : '',
            affectedSystems: $affectedSystems,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
        );
    }
}
