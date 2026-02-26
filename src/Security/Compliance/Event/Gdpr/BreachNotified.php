<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records that a supervisory authority has been notified of a breach.
 *
 * Supports controls for GDPR Article 33 notification to supervisory authority within 72 hours.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class BreachNotified extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $authority,
        public DateTimeImmutable $notifiedAt,
        public string $breachEventId,
        public DateTimeImmutable $responseDeadline,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'breach_notified';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'authority' => $this->authority,
            'notified_at' => $this->notifiedAt->format('Y-m-d\TH:i:s.uP'),
            'breach_event_id' => $this->breachEventId,
            'response_deadline' => $this->responseDeadline->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            authority: is_string($data['authority'] ?? null) ? $data['authority'] : '',
            notifiedAt: is_string($data['notified_at'] ?? null) ? new DateTimeImmutable($data['notified_at']) : new DateTimeImmutable(),
            breachEventId: is_string($data['breach_event_id'] ?? null) ? $data['breach_event_id'] : '',
            responseDeadline: is_string($data['response_deadline'] ?? null) ? new DateTimeImmutable($data['response_deadline']) : new DateTimeImmutable(),
        );
    }
}
