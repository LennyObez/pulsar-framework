<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Surveillance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\PmcfReport;

#[CoversClass(PmcfReport::class)]
final class PmcfReportTest extends TestCase
{
    #[Test]
    public function toArrayMinimal(): void
    {
        $report = new PmcfReport(
            id: 'pmcf-1',
            deviceIdentifier: 'UDI-001',
            reportDate: new DateTimeImmutable('2026-03-15'),
            clinicalEvaluationSummary: 'Clinical evidence supports safety and performance.',
        );

        $array = $report->toArray();

        self::assertSame('pmcf-1', $array['id']);
        self::assertSame('UDI-001', $array['device_identifier']);
        self::assertSame('2026-03-15', $array['report_date']);
        self::assertSame('Clinical evidence supports safety and performance.', $array['clinical_evaluation_summary']);
        self::assertArrayNotHasKey('patients_surveyed', $array);
        self::assertArrayNotHasKey('safety_conclusion', $array);
        self::assertArrayNotHasKey('performance_conclusion', $array);
        self::assertArrayNotHasKey('clinical_study_references', $array);
        self::assertArrayNotHasKey('literature_references', $array);
        self::assertArrayNotHasKey('benefit_risk_assessment', $array);
        self::assertArrayNotHasKey('next_review_date', $array);
    }

    #[Test]
    public function toArrayFull(): void
    {
        $report = new PmcfReport(
            id: 'pmcf-2',
            deviceIdentifier: 'UDI-002',
            reportDate: new DateTimeImmutable('2026-03-15'),
            clinicalEvaluationSummary: 'Comprehensive PMCF evaluation completed.',
            patientsSurveyed: 500,
            safetyConclusion: 'No new safety concerns identified.',
            performanceConclusion: 'Device performance meets specifications.',
            clinicalStudyReferences: ['STUDY-2025-001', 'STUDY-2025-002'],
            literatureReferences: ['DOI:10.1234/example'],
            benefitRiskAssessment: 'Favorable benefit-risk ratio maintained.',
            nextReviewDate: '2027-03-15',
        );

        $array = $report->toArray();

        self::assertSame(500, $array['patients_surveyed']);
        self::assertSame('No new safety concerns identified.', $array['safety_conclusion']);
        self::assertSame('Device performance meets specifications.', $array['performance_conclusion']);
        self::assertCount(2, $array['clinical_study_references']);
        self::assertCount(1, $array['literature_references']);
        self::assertSame('Favorable benefit-risk ratio maintained.', $array['benefit_risk_assessment']);
        self::assertSame('2027-03-15', $array['next_review_date']);
    }
}
