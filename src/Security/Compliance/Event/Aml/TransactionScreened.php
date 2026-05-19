<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a transaction screening against AML rules.
 *
 * Supports controls for AML transaction monitoring requirements.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class TransactionScreened extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $matchedRules
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $screenerIdentity,
        public string $transactionId,
        public string $customerPseudonym,
        public string $screeningResult,
        public array $matchedRules,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'aml';
    }

    public function eventType(): string
    {
        return 'transaction_screened';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'screener_identity' => $this->screenerIdentity,
            'transaction_id' => $this->transactionId,
            'customer_pseudonym' => $this->customerPseudonym,
            'screening_result' => $this->screeningResult,
            'matched_rules' => $this->matchedRules,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     screener_identity?: string,
     *     transaction_id?: string,
     *     customer_pseudonym?: string,
     *     screening_result?: string,
     *     matched_rules?: list<string>,
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
            screenerIdentity: $data['screener_identity'] ?? '',
            transactionId: $data['transaction_id'] ?? '',
            customerPseudonym: $data['customer_pseudonym'] ?? '',
            screeningResult: $data['screening_result'] ?? '',
            matchedRules: $data['matched_rules'] ?? [],
        );
    }
}
