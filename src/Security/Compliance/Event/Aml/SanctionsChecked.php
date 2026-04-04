<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a sanctions list check.
 *
 * Supports controls for AML/CFT sanctions screening requirements.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     checker_identity?: string,
     *     customer_pseudonym?: string,
     *     sanctions_list_version?: string,
     *     result?: string,
     *     match_details?: string,
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
            checkerIdentity: $data['checker_identity'] ?? '',
            customerPseudonym: $data['customer_pseudonym'] ?? '',
            sanctionsListVersion: $data['sanctions_list_version'] ?? '',
            result: $data['result'] ?? '',
            matchDetails: $data['match_details'] ?? '',
        );
    }
}
