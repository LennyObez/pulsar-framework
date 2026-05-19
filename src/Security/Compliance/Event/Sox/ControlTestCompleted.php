<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of an internal control test.
 *
 * Supports controls for SOX Section 404 testing of internal controls.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     tester_identity?: string,
     *     control_id?: string,
     *     control_description?: string,
     *     test_result?: string,
     *     findings?: string,
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
            controlId: $data['control_id'] ?? '',
            controlDescription: $data['control_description'] ?? '',
            testResult: $data['test_result'] ?? '',
            findings: $data['findings'] ?? '',
        );
    }
}
