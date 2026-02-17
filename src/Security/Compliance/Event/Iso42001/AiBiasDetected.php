<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records detection of bias in an AI model's outputs.
 *
 * Supports ISO 42001:2023 Clause 6.1.2 (AI risk assessment) and Annex A
 * control A.8 (transparency) by recording bias incidents for investigation.
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
            modelId: is_string($data['model_id'] ?? null) ? $data['model_id'] : '',
            biasType: is_string($data['bias_type'] ?? null) ? $data['bias_type'] : '',
            affectedGroup: is_string($data['affected_group'] ?? null) ? $data['affected_group'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            detectedBy: is_string($data['detected_by'] ?? null) ? $data['detected_by'] : '',
        );
    }
}
