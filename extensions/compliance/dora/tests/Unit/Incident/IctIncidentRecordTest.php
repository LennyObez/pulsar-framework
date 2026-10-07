<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Incident\IctIncidentClassification;
use Pulsar\Extension\Dora\Incident\IctIncidentRecord;
use Pulsar\Extension\Dora\Incident\IncidentReportingPhase;

#[CoversClass(IctIncidentRecord::class)]
final class IctIncidentRecordTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $record = new IctIncidentRecord(
            id: 'INC-001',
            detectedAt: $detected,
            description: 'Test incident',
            severity: 'high',
            classification: IctIncidentClassification::Major,
        );

        self::assertSame('INC-001', $record->id);
        self::assertSame($detected, $record->detectedAt);
        self::assertSame(IctIncidentClassification::Major, $record->classification);
        self::assertSame(IncidentReportingPhase::Detection, $record->reportingPhase);
        self::assertSame([], $record->affectedServices);
        self::assertSame([], $record->affectedClients);
        self::assertNull($record->estimatedClientsAffected);
        self::assertNull($record->dataLossDescription);
        self::assertNull($record->estimatedFinancialImpact);
        self::assertNull($record->resolvedAt);
        self::assertNull($record->rootCause);
        self::assertNull($record->remediationAction);
    }

    #[Test]
    public function isInitialNotificationOverdueReturnsTrueAfterFourHoursForMajorInDetection(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $fiveHoursLater = new DateTimeImmutable('2026-01-15T15:01:00+00:00');

        $record = new IctIncidentRecord(
            id: 'INC-002',
            detectedAt: $detected,
            description: 'Major incident',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
            reportingPhase: IncidentReportingPhase::Detection,
        );

        self::assertTrue($record->isInitialNotificationOverdue($fiveHoursLater));
    }

    #[Test]
    public function isInitialNotificationOverdueReturnsFalseWithinFourHours(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $threeHoursLater = new DateTimeImmutable('2026-01-15T13:00:00+00:00');

        $record = new IctIncidentRecord(
            id: 'INC-003',
            detectedAt: $detected,
            description: 'Recent major',
            severity: 'high',
            classification: IctIncidentClassification::Major,
        );

        self::assertFalse($record->isInitialNotificationOverdue($threeHoursLater));
    }

    #[Test]
    public function isInitialNotificationOverdueReturnsFalseForNonMajor(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $dayLater = new DateTimeImmutable('2026-01-16T10:00:00+00:00');

        $record = new IctIncidentRecord(
            id: 'INC-004',
            detectedAt: $detected,
            description: 'Minor incident',
            severity: 'low',
            classification: IctIncidentClassification::NonMajor,
        );

        self::assertFalse($record->isInitialNotificationOverdue($dayLater));
    }

    #[Test]
    public function isInitialNotificationOverdueReturnsFalseWhenAlreadyNotified(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $dayLater = new DateTimeImmutable('2026-01-16T10:00:00+00:00');

        $record = new IctIncidentRecord(
            id: 'INC-005',
            detectedAt: $detected,
            description: 'Already notified',
            severity: 'high',
            classification: IctIncidentClassification::Major,
            reportingPhase: IncidentReportingPhase::InitialNotification,
        );

        self::assertFalse($record->isInitialNotificationOverdue($dayLater));
    }

    #[Test]
    public function toArrayWithMinimalRecord(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $record = new IctIncidentRecord(
            id: 'INC-006',
            detectedAt: $detected,
            description: 'Minimal',
            severity: 'medium',
            classification: IctIncidentClassification::NonMajor,
        );

        $array = $record->toArray();

        self::assertSame('INC-006', $array['id']);
        self::assertSame('2026-01-15T10:00:00+00:00', $array['detected_at']);
        self::assertSame('Minimal', $array['description']);
        self::assertSame('medium', $array['severity']);
        self::assertSame('non_major', $array['classification']);
        self::assertSame('detection', $array['reporting_phase']);
        self::assertArrayNotHasKey('affected_services', $array);
        self::assertArrayNotHasKey('resolved_at', $array);
        self::assertArrayNotHasKey('root_cause', $array);
    }

    #[Test]
    public function toArrayWithFullRecord(): void
    {
        $detected = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $resolved = new DateTimeImmutable('2026-01-15T14:00:00+00:00');

        $record = new IctIncidentRecord(
            id: 'INC-007',
            detectedAt: $detected,
            description: 'Full incident',
            severity: 'critical',
            classification: IctIncidentClassification::Major,
            reportingPhase: IncidentReportingPhase::FinalReport,
            affectedServices: ['payments', 'auth'],
            affectedClients: ['retail', 'corporate'],
            estimatedClientsAffected: 5000,
            dataLossDescription: 'Temporary unavailability',
            estimatedFinancialImpact: 250_000.00,
            geographicalSpread: 'EU-wide',
            resolvedAt: $resolved,
            rootCause: 'Database failover failure',
            remediationAction: 'Upgraded failover mechanism',
        );

        $array = $record->toArray();

        self::assertSame(['payments', 'auth'], $array['affected_services']);
        self::assertSame(['retail', 'corporate'], $array['affected_clients']);
        self::assertSame(5000, $array['estimated_clients_affected']);
        self::assertSame('Temporary unavailability', $array['data_loss_description']);
        self::assertSame(250_000.00, $array['estimated_financial_impact']);
        self::assertSame('EU-wide', $array['geographical_spread']);
        self::assertSame('2026-01-15T14:00:00+00:00', $array['resolved_at']);
        self::assertSame('Database failover failure', $array['root_cause']);
        self::assertSame('Upgraded failover mechanism', $array['remediation_action']);
    }

    /**
     * @return iterable<string, array{IctIncidentClassification, string}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'major' => [IctIncidentClassification::Major, 'major'];
        yield 'non_major' => [IctIncidentClassification::NonMajor, 'non_major'];
    }

    #[Test]
    #[DataProvider('classificationProvider')]
    public function classificationEnumHasExpectedValue(IctIncidentClassification $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{IncidentReportingPhase, string}>
     */
    public static function reportingPhaseProvider(): iterable
    {
        yield 'detection' => [IncidentReportingPhase::Detection, 'detection'];
        yield 'initial_notification' => [IncidentReportingPhase::InitialNotification, 'initial_notification'];
        yield 'intermediate_report' => [IncidentReportingPhase::IntermediateReport, 'intermediate_report'];
        yield 'final_report' => [IncidentReportingPhase::FinalReport, 'final_report'];
        yield 'closed' => [IncidentReportingPhase::Closed, 'closed'];
    }

    #[Test]
    #[DataProvider('reportingPhaseProvider')]
    public function reportingPhaseEnumHasExpectedValue(IncidentReportingPhase $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }
}
