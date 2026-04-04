<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records a change to access control settings.
 *
 * Supports controls for PCI-DSS Requirement 7 restrict access by business need-to-know.
 * @api
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
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'pci_dss';
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
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     operator_identity?: string,
     *     target_identity?: string,
     *     change_type?: string,
     *     permission?: string,
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
            operatorIdentity: $data['operator_identity'] ?? '',
            targetIdentity: $data['target_identity'] ?? '',
            changeType: $data['change_type'] ?? '',
            permission: $data['permission'] ?? '',
            reason: $data['reason'] ?? '',
        );
    }
}
