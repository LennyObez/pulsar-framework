<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Surveillance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\AdverseEventSummary;
use Pulsar\Extension\MedicalDevices\Surveillance\PostMarketSurveillanceReport;
use Pulsar\Extension\MedicalDevices\Surveillance\ReportType;

#[CoversClass(PostMarketSurveillanceReport::class)]
final class PostMarketSurveillanceReportTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $report = new PostMarketSurveillanceReport(
            id: 'PMS-001',
            deviceIdentifier: 'DI-001',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-06-30',
            reportDate: new DateTimeImmutable('2025-07-15'),
            manufacturer: 'MedTech Corp',
        );

        self::assertSame('PMS-001', $report->id);
        self::assertSame(ReportType::PmsReport, $report->reportType);
        self::assertSame([], $report->adverseEvents);
        self::assertNull($report->totalUnitsDistributed);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $report = new PostMarketSurveillanceReport(
            id: 'PMS-001',
            deviceIdentifier: 'DI-001',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-06-30',
            reportDate: new DateTimeImmutable('2025-07-15'),
            manufacturer: 'MedTech Corp',
        );

        $data = $report->toArray();

        self::assertSame('PMS-001', $data['id']);
        self::assertSame('2025-07-15', $data['report_date']);
        self::assertSame('pms_report', $data['report_type']);
        self::assertArrayNotHasKey('total_units_distributed', $data);
        self::assertArrayNotHasKey('adverse_events', $data);
    }

    #[Test]
    public function toArrayIncludesAdverseEvents(): void
    {
        $event = new AdverseEventSummary(
            eventType: 'Device malfunction',
            occurrenceCount: 5,
            severityAssessment: 'moderate',
        );

        $report = new PostMarketSurveillanceReport(
            id: 'PMS-002',
            deviceIdentifier: 'DI-001',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-06-30',
            reportDate: new DateTimeImmutable('2025-07-15'),
            manufacturer: 'MedTech Corp',
            adverseEvents: [$event],
            totalUnitsDistributed: 10000,
            totalComplaintsReceived: 12,
        );

        $data = $report->toArray();

        self::assertIsArray($data['adverse_events']);
        /** @var list<array<string, mixed>> $adverseEvents */
        $adverseEvents = $data['adverse_events'];
        self::assertCount(1, $adverseEvents);
        self::assertSame('Device malfunction', $adverseEvents[0]['event_type']);
        self::assertSame(10000, $data['total_units_distributed']);
        self::assertSame(12, $data['total_complaints_received']);
    }

    #[Test]
    public function toArrayIncludesOptionalFields(): void
    {
        $report = new PostMarketSurveillanceReport(
            id: 'PMS-003',
            deviceIdentifier: 'DI-001',
            reportPeriodStart: '2025-01-01',
            reportPeriodEnd: '2025-06-30',
            reportDate: new DateTimeImmutable('2025-07-15'),
            manufacturer: 'MedTech Corp',
            dataSourceDescriptions: ['Hospital complaints database', 'National registry'],
            trendAnalysisSummary: 'No significant increase detected',
            correctiveActions: ['Updated labeling', 'Added warning'],
            conclusion: 'Device continues to meet safety requirements',
            reportType: ReportType::Psur,
        );

        $data = $report->toArray();

        self::assertIsArray($data['data_source_descriptions']);
        self::assertCount(2, $data['data_source_descriptions']);
        self::assertSame('No significant increase detected', $data['trend_analysis_summary']);
        self::assertIsArray($data['corrective_actions']);
        self::assertCount(2, $data['corrective_actions']);
        self::assertSame('psur', $data['report_type']);
    }
}
