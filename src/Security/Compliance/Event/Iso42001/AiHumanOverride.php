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
 * Records a human override of an AI-assisted decision.
 *
 * Supports ISO 42001:2023 Annex A control A.8 (transparency and explainability)
 * by capturing when humans intervene in AI decision-making.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AiHumanOverride extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $modelId,
        public string $decisionId,
        public string $overriddenBy,
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'iso42001';
    }

    public function eventType(): string
    {
        return 'ai_human_override';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'model_id' => $this->modelId,
            'decision_id' => $this->decisionId,
            'overridden_by' => $this->overriddenBy,
            'reason' => $this->reason,
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
            decisionId: is_string($data['decision_id'] ?? null) ? $data['decision_id'] : '',
            overriddenBy: is_string($data['overridden_by'] ?? null) ? $data['overridden_by'] : '',
            reason: is_string($data['reason'] ?? null) ? $data['reason'] : '',
        );
    }
}
