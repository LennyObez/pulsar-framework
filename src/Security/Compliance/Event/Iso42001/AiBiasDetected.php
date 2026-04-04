<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records detection of bias in an AI model's outputs.
 *
 * Supports ISO 42001:2023 Clause 6.1.2 (AI risk assessment) and Annex A
 * control A.8 (transparency) by recording bias incidents for investigation.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AiBiasDetected extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modelId,
        public string $biasType,
        public string $affectedGroup,
        public string $description,
        public string $detectedBy,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'iso42001';
    }

    public function eventType(): string
    {
        return 'ai_bias_detected';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'model_id' => $this->modelId,
            'bias_type' => $this->biasType,
            'affected_group' => $this->affectedGroup,
            'description' => $this->description,
            'detected_by' => $this->detectedBy,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     model_id?: string,
     *     bias_type?: string,
     *     affected_group?: string,
     *     description?: string,
     *     detected_by?: string,
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
            biasType: $data['bias_type'] ?? '',
            affectedGroup: $data['affected_group'] ?? '',
            description: $data['description'] ?? '',
            detectedBy: $data['detected_by'] ?? '',
        );
    }
}
