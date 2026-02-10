<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

use function is_int;
use function is_string;

/**
 * Records completion of a penetration test.
 *
 * Supports controls for PCI-DSS Requirement 11.3 penetration testing.
 */
#[Api(since: '1.0.0')]
#[RequiresEnvelope]
final readonly class PenetrationTestCompleted extends ComplianceEvent
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $nonce,
        public string $testerIdentity,
        public string $scope,
        public int $findingsCount,
        public int $criticalFindings,
        public DateTimeImmutable $testDate,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $nonce);
    }

    public function regulation(): string
    {
        return 'pci_dss';
    }

    public function eventType(): string
    {
        return 'penetration_test_completed';
    }

    public function toArray(): array
    {
        return [
            ...$this->baseToArray(),
            'schema_version' => self::SCHEMA_VERSION,
            'tester_identity' => $this->testerIdentity,
            'scope' => $this->scope,
            'findings_count' => $this->findingsCount,
            'critical_findings' => $this->criticalFindings,
            'test_date' => $this->testDate->format('Y-m-d\TH:i:s.uP'),
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
            scope: is_string($data['scope'] ?? null) ? $data['scope'] : '',
            findingsCount: is_int($data['findings_count'] ?? null) ? $data['findings_count'] : 0,
            criticalFindings: is_int($data['critical_findings'] ?? null) ? $data['critical_findings'] : 0,
            testDate: is_string($data['test_date'] ?? null) ? new DateTimeImmutable($data['test_date']) : new DateTimeImmutable(),
        );
    }
}
