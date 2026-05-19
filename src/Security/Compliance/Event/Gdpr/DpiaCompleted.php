<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a Data Protection Impact Assessment.
 *
 * Supports controls for GDPR Article 35 data protection impact assessment.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class DpiaCompleted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $mitigations
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $assessorIdentity,
        public string $processingActivity,
        public string $riskLevel,
        public array $mitigations,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'gdpr';
    }

    public function eventType(): string
    {
        return 'dpia_completed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'assessor_identity' => $this->assessorIdentity,
            'processing_activity' => $this->processingActivity,
            'risk_level' => $this->riskLevel,
            'mitigations' => $this->mitigations,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     assessor_identity?: string,
     *     processing_activity?: string,
     *     risk_level?: string,
     *     mitigations?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            assessorIdentity: $data['assessor_identity'] ?? '',
            processingActivity: $data['processing_activity'] ?? '',
            riskLevel: $data['risk_level'] ?? '',
            mitigations: $data['mitigations'] ?? [],
        );
    }
}
