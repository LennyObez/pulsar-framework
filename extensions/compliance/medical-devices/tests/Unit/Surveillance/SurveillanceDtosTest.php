<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Surveillance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\AdverseEventSummary;
use Pulsar\Extension\MedicalDevices\Surveillance\TrendResult;

#[CoversClass(AdverseEventSummary::class)]
#[CoversClass(TrendResult::class)]
final class SurveillanceDtosTest extends TestCase
{
    // --- AdverseEventSummary ---

    #[Test]
    public function adverseEventSummaryToArrayWithMinimalFields(): void
    {
        $summary = new AdverseEventSummary(
            eventType: 'device_malfunction',
            occurrenceCount: 5,
            severityAssessment: 'moderate',
        );

        $array = $summary->toArray();

        self::assertSame('device_malfunction', $array['event_type']);
        self::assertSame(5, $array['occurrence_count']);
        self::assertSame('moderate', $array['severity_assessment']);
        self::assertArrayNotHasKey('patient_outcome', $array);
        self::assertArrayNotHasKey('root_cause_analysis', $array);
    }

    #[Test]
    public function adverseEventSummaryToArrayWithAllFields(): void
    {
        $summary = new AdverseEventSummary(
            eventType: 'patient_injury',
            occurrenceCount: 2,
            severityAssessment: 'serious',
            patientOutcome: 'Recovered without permanent damage',
            rootCauseAnalysis: 'Manufacturing defect in batch B-2026-01',
        );

        $array = $summary->toArray();

        self::assertSame('Recovered without permanent damage', $array['patient_outcome']);
        self::assertSame('Manufacturing defect in batch B-2026-01', $array['root_cause_analysis']);
    }

    // --- TrendResult ---

    #[Test]
    public function trendResultToArrayWithMinimalFields(): void
    {
        $result = new TrendResult(
            deviceIdentifier: 'UDI-001',
            periodStart: '2025-01-01',
            periodEnd: '2025-12-31',
            totalEvents: 15,
            significantIncrease: false,
        );

        $array = $result->toArray();

        self::assertSame('UDI-001', $array['device_identifier']);
        self::assertSame('2025-01-01', $array['period_start']);
        self::assertSame('2025-12-31', $array['period_end']);
        self::assertSame(15, $array['total_events']);
        self::assertFalse($array['significant_increase']);
        self::assertArrayNotHasKey('change_percentage', $array);
        self::assertArrayNotHasKey('summary', $array);
    }

    #[Test]
    public function trendResultToArrayWithAllFields(): void
    {
        $result = new TrendResult(
            deviceIdentifier: 'UDI-002',
            periodStart: '2025-07-01',
            periodEnd: '2025-12-31',
            totalEvents: 42,
            significantIncrease: true,
            changePercentage: 35.5,
            summary: 'Adverse events increased 35.5% in H2 2025',
        );

        $array = $result->toArray();

        self::assertTrue($array['significant_increase']);
        self::assertSame(35.5, $array['change_percentage']);
        self::assertSame('Adverse events increased 35.5% in H2 2025', $array['summary']);
    }
}
