<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Incident\IctIncidentClassification;
use Pulsar\Extension\Dora\Incident\IctIncidentRecord;
use Pulsar\Extension\Dora\Incident\IncidentReportingPhase;

#[CoversClass(IctIncidentRecord::class)]
final class IctIncidentRecordTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-001',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'Payment system unresponsive',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
        );

        self::assertSame(IncidentReportingPhase::Detection, $record->reportingPhase);
        self::assertSame([], $record->affectedServices);
        self::assertNull($record->resolvedAt);
    }

    #[Test]
    public function initialNotificationOverdueForMajorIncidentAfter4Hours(): void
    {
        $detected = new DateTimeImmutable('2025-03-14T10:00:00+00:00');
        $record = new IctIncidentRecord(
            id: 'INC-001',
            detectedAt: $detected,
            description: 'System outage',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
        );

        // 3 hours later — not overdue
        $threeHoursLater = new DateTimeImmutable('2025-03-14T13:00:00+00:00');
        self::assertFalse($record->isInitialNotificationOverdue($threeHoursLater));

        // 5 hours later — overdue
        $fiveHoursLater = new DateTimeImmutable('2025-03-14T15:00:00+00:00');
        self::assertTrue($record->isInitialNotificationOverdue($fiveHoursLater));
    }

    #[Test]
    public function nonMajorIncidentIsNeverOverdue(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-002',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'Minor issue',
            severity: 'low',
            classification: IctIncidentClassification::NonMajor,
        );

        $dayLater = new DateTimeImmutable('2025-03-15T10:00:00+00:00');
        self::assertFalse($record->isInitialNotificationOverdue($dayLater));
    }

    #[Test]
    public function notOverdueAfterInitialNotificationSent(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-003',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'System outage',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
            reportingPhase: IncidentReportingPhase::InitialNotification,
        );

        $dayLater = new DateTimeImmutable('2025-03-15T10:00:00+00:00');
        self::assertFalse($record->isInitialNotificationOverdue($dayLater));
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-001',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'Payment system outage',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
        );

        $data = $record->toArray();

        self::assertSame('INC-001', $data['id']);
        self::assertSame('critical', $data['severity']);
        self::assertSame('major', $data['classification']);
        self::assertSame('detection', $data['reporting_phase']);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-001',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'Outage',
            severity: 'high',
            classification: IctIncidentClassification::Major,
        );

        $data = $record->toArray();

        self::assertArrayNotHasKey('affected_services', $data);
        self::assertArrayNotHasKey('estimated_clients_affected', $data);
        self::assertArrayNotHasKey('resolved_at', $data);
        self::assertArrayNotHasKey('root_cause', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $record = new IctIncidentRecord(
            id: 'INC-002',
            detectedAt: new DateTimeImmutable('2025-03-14T10:00:00+00:00'),
            description: 'Major outage',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
            reportingPhase: IncidentReportingPhase::FinalReport,
            affectedServices: ['Payments', 'Trading'],
            affectedClients: ['Retail', 'Institutional'],
            estimatedClientsAffected: 50000,
            dataLossDescription: 'No data loss',
            estimatedFinancialImpact: 1500000.0,
            geographicalSpread: 'EU-wide',
            resolvedAt: new DateTimeImmutable('2025-03-14T14:00:00+00:00'),
            rootCause: 'Network switch failure',
            remediationAction: 'Replaced redundant switch',
        );

        /** @var array<string, mixed> $data */
        $data = $record->toArray();

        self::assertSame('final_report', $data['reporting_phase']);
        /** @var list<string> $services */
        $services = $data['affected_services'];
        self::assertCount(2, $services);
        self::assertSame(50000, $data['estimated_clients_affected']);
        self::assertSame(1500000.0, $data['estimated_financial_impact']);
        self::assertSame('EU-wide', $data['geographical_spread']);
        self::assertSame('Network switch failure', $data['root_cause']);
    }

    #[Test]
    public function reportingPhaseEnumHasAllPhases(): void
    {
        self::assertCount(5, IncidentReportingPhase::cases());
        self::assertSame('detection', IncidentReportingPhase::Detection->value);
        self::assertSame('closed', IncidentReportingPhase::Closed->value);
    }
}
