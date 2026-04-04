<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records the completion of an AI impact assessment.
 *
 * Supports ISO 42001:2023 Clause 6.1.2 (AI risk assessment) and Clause 8.2
 * (AI system impact assessment) by capturing assessment outcomes.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AiImpactAssessed extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modelId,
        public float $riskScore,
        public int $findingsCount,
        public string $assessedBy,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'iso42001';
    }

    public function eventType(): string
    {
        return 'ai_impact_assessed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'model_id' => $this->modelId,
            'risk_score' => $this->riskScore,
            'findings_count' => $this->findingsCount,
            'assessed_by' => $this->assessedBy,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     model_id?: string,
     *     risk_score?: float|int,
     *     findings_count?: int,
     *     assessed_by?: string,
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
            modelId: $data['model_id'] ?? '',
            riskScore: (float) ($data['risk_score'] ?? 0.0),
            findingsCount: $data['findings_count'] ?? 0,
            assessedBy: $data['assessed_by'] ?? '',
        );
    }
}
