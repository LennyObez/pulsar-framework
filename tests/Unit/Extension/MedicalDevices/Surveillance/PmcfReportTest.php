<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Surveillance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Surveillance\PmcfReport;

#[CoversClass(PmcfReport::class)]
final class PmcfReportTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $report = new PmcfReport(
            id: 'PMCF-001',
            deviceIdentifier: 'DI-001',
            reportDate: new DateTimeImmutable('2025-06-15'),
            clinicalEvaluationSummary: 'Device demonstrates continued safety and performance.',
        );

        self::assertSame('PMCF-001', $report->id);
        self::assertNull($report->patientsSurveyed);
        self::assertSame([], $report->clinicalStudyReferences);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $report = new PmcfReport(
            id: 'PMCF-001',
            deviceIdentifier: 'DI-001',
            reportDate: new DateTimeImmutable('2025-06-15'),
            clinicalEvaluationSummary: 'Summary',
        );

        $data = $report->toArray();

        self::assertSame('PMCF-001', $data['id']);
        self::assertSame('Summary', $data['clinical_evaluation_summary']);
        self::assertArrayNotHasKey('patients_surveyed', $data);
        self::assertArrayNotHasKey('safety_conclusion', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $report = new PmcfReport(
            id: 'PMCF-002',
            deviceIdentifier: 'DI-002',
            reportDate: new DateTimeImmutable('2025-06-15'),
            clinicalEvaluationSummary: 'Comprehensive evaluation',
            patientsSurveyed: 1500,
            safetyConclusion: 'Acceptable benefit-risk profile',
            performanceConclusion: 'Meets specifications',
            clinicalStudyReferences: ['STUDY-001', 'STUDY-002'],
            literatureReferences: ['DOI:10.xxxx/yyyy'],
            benefitRiskAssessment: 'Favorable',
            nextReviewDate: '2026-06-15',
        );

        $data = $report->toArray();

        self::assertSame(1500, $data['patients_surveyed']);
        self::assertIsArray($data['clinical_study_references']);
        self::assertCount(2, $data['clinical_study_references']);
        self::assertIsArray($data['literature_references']);
        self::assertCount(1, $data['literature_references']);
        self::assertSame('Favorable', $data['benefit_risk_assessment']);
        self::assertSame('2026-06-15', $data['next_review_date']);
    }
}
