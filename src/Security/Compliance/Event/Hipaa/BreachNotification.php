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
use function is_int;
use function is_string;

/**
 * Records a HIPAA breach notification event.
 *
 * Supports controls for HIPAA Breach Notification Rule (45 CFR 164.400-414).
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreachNotification extends ComplianceEvent
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
        public string $reporterIdentity,
        public int $affectedCount,
        public array $phiCategories,
        public DateTimeImmutable $discoveryDate,
        public DateTimeImmutable $notificationDeadline,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'hipaa';
    }

    public function eventType(): string
    {
        return 'breach_notification';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'reporter_identity' => $this->reporterIdentity,
            'affected_count' => $this->affectedCount,
            'phi_categories' => $this->phiCategories,
            'discovery_date' => $this->discoveryDate->format('Y-m-d\TH:i:s.uP'),
            'notification_deadline' => $this->notificationDeadline->format('Y-m-d\TH:i:s.uP'),
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
            reporterIdentity: is_string($data['reporter_identity'] ?? null) ? $data['reporter_identity'] : '',
            affectedCount: is_int($data['affected_count'] ?? null) ? $data['affected_count'] : 0,
            phiCategories: $phiCategories,
            discoveryDate: is_string($data['discovery_date'] ?? null) ? new DateTimeImmutable($data['discovery_date']) : new DateTimeImmutable(),
            notificationDeadline: is_string($data['notification_deadline'] ?? null) ? new DateTimeImmutable($data['notification_deadline']) : new DateTimeImmutable(),
        );
    }
}
