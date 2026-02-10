<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records completion of a sanctions list check.
 *
 * Supports controls for AML/CFT sanctions screening requirements.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class SanctionsChecked extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $checkerIdentity,
        public string $customerPseudonym,
        public string $sanctionsListVersion,
        public string $result,
        public string $matchDetails,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'aml';
    }

    public function eventType(): string
    {
        return 'sanctions_checked';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'checker_identity' => $this->checkerIdentity,
            'customer_pseudonym' => $this->customerPseudonym,
            'sanctions_list_version' => $this->sanctionsListVersion,
            'result' => $this->result,
            'match_details' => $this->matchDetails,
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
            checkerIdentity: is_string($data['checker_identity'] ?? null) ? $data['checker_identity'] : '',
            customerPseudonym: is_string($data['customer_pseudonym'] ?? null) ? $data['customer_pseudonym'] : '',
            sanctionsListVersion: is_string($data['sanctions_list_version'] ?? null) ? $data['sanctions_list_version'] : '',
            result: is_string($data['result'] ?? null) ? $data['result'] : '',
            matchDetails: is_string($data['match_details'] ?? null) ? $data['match_details'] : '',
        );
    }
}
