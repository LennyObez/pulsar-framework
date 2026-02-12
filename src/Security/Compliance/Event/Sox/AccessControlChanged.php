<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records a change to access controls over financial systems.
 *
 * Supports controls for SOX Section 404 management assessment of internal controls.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class AccessControlChanged extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $operatorIdentity,
        public string $targetIdentity,
        public string $changeType,
        public string $permission,
        public string $justification,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'sox';
    }

    public function eventType(): string
    {
        return 'access_control_changed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'operator_identity' => $this->operatorIdentity,
            'target_identity' => $this->targetIdentity,
            'change_type' => $this->changeType,
            'permission' => $this->permission,
            'justification' => $this->justification,
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
            operatorIdentity: is_string($data['operator_identity'] ?? null) ? $data['operator_identity'] : '',
            targetIdentity: is_string($data['target_identity'] ?? null) ? $data['target_identity'] : '',
            changeType: is_string($data['change_type'] ?? null) ? $data['change_type'] : '',
            permission: is_string($data['permission'] ?? null) ? $data['permission'] : '',
            justification: is_string($data['justification'] ?? null) ? $data['justification'] : '',
        );
    }
}
