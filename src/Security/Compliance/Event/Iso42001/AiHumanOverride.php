<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records a human override of an AI-assisted decision.
 *
 * Supports ISO 42001:2023 Annex A control A.8 (transparency and explainability)
 * by capturing when humans intervene in AI decision-making.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     model_id?: string,
     *     decision_id?: string,
     *     overridden_by?: string,
     *     reason?: string,
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
            decisionId: $data['decision_id'] ?? '',
            overriddenBy: $data['overridden_by'] ?? '',
            reason: $data['reason'] ?? '',
        );
    }
}
