<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $mitigations */
        $mitigations = is_array($data['mitigations'] ?? null) ? array_values($data['mitigations']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            assessorIdentity: is_string($data['assessor_identity'] ?? null) ? $data['assessor_identity'] : '',
            processingActivity: is_string($data['processing_activity'] ?? null) ? $data['processing_activity'] : '',
            riskLevel: is_string($data['risk_level'] ?? null) ? $data['risk_level'] : '',
            mitigations: $mitigations,
        );
    }
}
