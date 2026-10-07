<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a digital operational resilience test.
 *
 * Supports controls for DORA Article 24 testing of ICT tools and systems.
 * @api
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ResilienceTestCompleted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $testerIdentity,
        public string $testType,
        public string $targetSystem,
        public string $result,
        public string $recoveryTimeActual,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'dora';
    }

    public function eventType(): string
    {
        return 'resilience_test_completed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'tester_identity' => $this->testerIdentity,
            'test_type' => $this->testType,
            'target_system' => $this->targetSystem,
            'result' => $this->result,
            'recovery_time_actual' => $this->recoveryTimeActual,
        ];
    }

    /**
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     tester_identity?: string,
     *     test_type?: string,
     *     target_system?: string,
     *     result?: string,
     *     recovery_time_actual?: string,
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
            testerIdentity: $data['tester_identity'] ?? '',
            testType: $data['test_type'] ?? '',
            targetSystem: $data['target_system'] ?? '',
            result: $data['result'] ?? '',
            recoveryTimeActual: $data['recovery_time_actual'] ?? '',
        );
    }
}
