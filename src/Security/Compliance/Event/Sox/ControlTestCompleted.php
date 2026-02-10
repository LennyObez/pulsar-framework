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
 * Records completion of an internal control test.
 *
 * Supports controls for SOX Section 404 testing of internal controls.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class ControlTestCompleted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $testerIdentity,
        public string $controlId,
        public string $controlDescription,
        public string $testResult,
        public string $findings,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'sox';
    }

    public function eventType(): string
    {
        return 'control_test_completed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'tester_identity' => $this->testerIdentity,
            'control_id' => $this->controlId,
            'control_description' => $this->controlDescription,
            'test_result' => $this->testResult,
            'findings' => $this->findings,
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
            controlId: is_string($data['control_id'] ?? null) ? $data['control_id'] : '',
            controlDescription: is_string($data['control_description'] ?? null) ? $data['control_description'] : '',
            testResult: is_string($data['test_result'] ?? null) ? $data['test_result'] : '',
            findings: is_string($data['findings'] ?? null) ? $data['findings'] : '',
        );
    }
}
