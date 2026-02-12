<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function array_values;
use function is_array;
use function is_string;

/**
 * Records completion of a transaction screening against AML rules.
 *
 * Supports controls for AML transaction monitoring requirements.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $matchedRules */
        $matchedRules = is_array($data['matched_rules'] ?? null) ? array_values($data['matched_rules']) : [];

        return new self(
            eventId: is_string($data['event_id'] ?? null) ? $data['event_id'] : '',
            occurredAt: is_string($data['occurred_at'] ?? null) ? new DateTimeImmutable($data['occurred_at']) : new DateTimeImmutable(),
            correlationId: is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : '',
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : '',
            screenerIdentity: is_string($data['screener_identity'] ?? null) ? $data['screener_identity'] : '',
            transactionId: is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : '',
            customerPseudonym: is_string($data['customer_pseudonym'] ?? null) ? $data['customer_pseudonym'] : '',
            screeningResult: is_string($data['screening_result'] ?? null) ? $data['screening_result'] : '',
            matchedRules: $matchedRules,
        );
    }
}
