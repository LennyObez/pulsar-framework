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
 * Records a security incident affecting ePHI.
 *
 * Supports controls for HIPAA Security Rule incident response (45 CFR 164.308(a)(6)).
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class SecurityIncident extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $containmentActions
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $reporterIdentity,
        public string $incidentType,
        public string $severity,
        public string $description,
        public array $containmentActions,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'security_incident';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'incident_type' => $this->incidentType,
            'severity' => $this->severity,
            'description' => $this->description,
            'containment_actions' => $this->containmentActions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $containmentActions */
        $containmentActions = is_array($data['containment_actions'] ?? null) ? array_values($data['containment_actions']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            reporterIdentity: is_string($data['reporter_identity'] ?? null) ? $data['reporter_identity'] : '',
            incidentType: is_string($data['incident_type'] ?? null) ? $data['incident_type'] : '',
            severity: is_string($data['severity'] ?? null) ? $data['severity'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            containmentActions: $containmentActions,
        );
    }
}
