<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Attribute\RequiresEnvelope;
use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * Records completion of a penetration test.
 *
 * Supports controls for PCI-DSS Requirement 11.3 penetration testing.
 * @api
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
     * @param array{
     *     event_id?: string,
     *     occurred_at?: string,
     *     correlation_id?: string,
     *     nonce?: string,
     *     tester_identity?: string,
     *     scope?: string,
     *     findings_count?: int,
     *     critical_findings?: int,
     *     test_date?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $occurredAt = $data['occurred_at'] ?? null;
        $testDate = $data['test_date'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            occurredAt: $occurredAt !== null ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable(),
            correlationId: $data['correlation_id'] ?? '',
            nonce: $data['nonce'] ?? '',
            testerIdentity: $data['tester_identity'] ?? '',
            scope: $data['scope'] ?? '',
            findingsCount: $data['findings_count'] ?? 0,
            criticalFindings: $data['critical_findings'] ?? 0,
            testDate: $testDate !== null ? new DateTimeImmutable($testDate) : new DateTimeImmutable(),
        );
    }
}
