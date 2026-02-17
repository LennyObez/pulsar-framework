<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_string;

/**
 * Records completion of a digital operational resilience test.
 *
 * Supports controls for DORA Article 24 testing of ICT tools and systems.
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
            testerIdentity: is_string($data['tester_identity'] ?? null) ? $data['tester_identity'] : '',
            testType: is_string($data['test_type'] ?? null) ? $data['test_type'] : '',
            targetSystem: is_string($data['target_system'] ?? null) ? $data['target_system'] : '',
            result: is_string($data['result'] ?? null) ? $data['result'] : '',
            recoveryTimeActual: is_string($data['recovery_time_actual'] ?? null) ? $data['recovery_time_actual'] : '',
        );
    }
}
