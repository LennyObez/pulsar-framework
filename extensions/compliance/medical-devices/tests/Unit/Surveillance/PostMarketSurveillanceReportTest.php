<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Surveillance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\AdverseEventSummary;
use Pulsar\Extension\MedicalDevices\Surveillance\PostMarketSurveillanceReport;
use Pulsar\Extension\MedicalDevices\Surveillance\ReportType;

#[CoversClass(PostMarketSurveillanceReport::class)]
#[CoversClass(AdverseEventSummary::class)]
final class PostMarketSurveillanceReportTest extends TestCase
{
    #[Test]
    public function toArrayMinimal(): void
    {
        $report = new PostMarketSurveillanceReport(
            id: 'pms-1',
            deviceIdentifier: 'UDI-001',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-12-31',
            reportDate: new DateTimeImmutable('2026-01-15'),
            manufacturer: 'Acme Medical',
        );

        $array = $report->toArray();

        self::assertSame('pms-1', $array['id']);
        self::assertSame('UDI-001', $array['device_identifier']);
        self::assertSame('2025-01-01', $array['report_period_start']);
        self::assertSame('2025-12-31', $array['report_period_end']);
        self::assertSame('2026-01-15', $array['report_date']);
        self::assertSame('Acme Medical', $array['manufacturer']);
        self::assertSame('pms_report', $array['report_type']);
        self::assertArrayNotHasKey('data_source_descriptions', $array);
        self::assertArrayNotHasKey('total_units_distributed', $array);
        self::assertArrayNotHasKey('adverse_events', $array);
    }

    #[Test]
    public function toArrayFull(): void
    {
        $adverse = new AdverseEventSummary(
            eventType: 'Skin irritation',
            occurrenceCount: 12,
            severityAssessment: 'Non-serious',
            patientOutcome: 'Resolved without treatment',
            rootCauseAnalysis: 'Adhesive sensitivity',
        );

        $report = new PostMarketSurveillanceReport(
            id: 'pms-2',
            deviceIdentifier: 'UDI-002',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-06-30',
            reportDate: new DateTimeImmutable('2025-07-15'),
            manufacturer: 'MedCorp',
            dataSourceDescriptions: ['Customer complaints', 'Regulatory databases'],
            totalUnitsDistributed: 50000,
            totalComplaintsReceived: 47,
            adverseEvents: [$adverse],
            trendAnalysisSummary: 'Slight increase in skin irritation reports.',
            correctiveActions: ['Updated adhesive formulation'],
            conclusion: 'Device continues to meet safety requirements.',
            reportType: ReportType::Psur,
        );

        $array = $report->toArray();

        self::assertSame('psur', $array['report_type']);
        self::assertCount(2, $array['data_source_descriptions']);
        self::assertSame(50000, $array['total_units_distributed']);
        self::assertSame(47, $array['total_complaints_received']);
        self::assertCount(1, $array['adverse_events']);
        self::assertSame('Skin irritation', $array['adverse_events'][0]['event_type']);
        self::assertSame(12, $array['adverse_events'][0]['occurrence_count']);
        self::assertSame('Resolved without treatment', $array['adverse_events'][0]['patient_outcome']);
        self::assertSame('Adhesive sensitivity', $array['adverse_events'][0]['root_cause_analysis']);
        self::assertCount(1, $array['corrective_actions']);
        self::assertSame('Device continues to meet safety requirements.', $array['conclusion']);
    }

    #[Test]
    public function adverseEventSummaryToArrayMinimal(): void
    {
        $event = new AdverseEventSummary(
            eventType: 'Device malfunction',
            occurrenceCount: 3,
            severityAssessment: 'Serious',
        );

        $array = $event->toArray();

        self::assertSame('Device malfunction', $array['event_type']);
        self::assertSame(3, $array['occurrence_count']);
        self::assertSame('Serious', $array['severity_assessment']);
        self::assertArrayNotHasKey('patient_outcome', $array);
        self::assertArrayNotHasKey('root_cause_analysis', $array);
    }

    /**
     * @return iterable<string, array{ReportType, string}>
     */
    public static function reportTypeProvider(): iterable
    {
        yield 'PMS Report' => [ReportType::PmsReport, 'pms_report'];
        yield 'PSUR' => [ReportType::Psur, 'psur'];
        yield 'PMCF' => [ReportType::Pmcf, 'pmcf'];
    }

    #[Test]
    #[DataProvider('reportTypeProvider')]
    public function reportTypeValues(ReportType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }
}
