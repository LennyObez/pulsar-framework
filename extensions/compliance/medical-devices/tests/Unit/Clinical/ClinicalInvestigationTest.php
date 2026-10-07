<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Clinical;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Clinical\ClinicalInvestigation;
use Pulsar\Extension\MedicalDevices\Clinical\ClinicalInvestigationStatus;

#[CoversClass(ClinicalInvestigation::class)]
final class ClinicalInvestigationTest extends TestCase
{
    #[Test]
    public function toArrayMinimalRecord(): void
    {
        $ci = new ClinicalInvestigation(
            id: 'CI-001',
            deviceIdentifier: 'DI-500',
            title: 'Safety trial for cardiac monitor',
            sponsor: 'MedTech Corp',
            status: ClinicalInvestigationStatus::Planned,
        );

        $array = $ci->toArray();

        self::assertSame('CI-001', $array['id']);
        self::assertSame('DI-500', $array['device_identifier']);
        self::assertSame('Safety trial for cardiac monitor', $array['title']);
        self::assertSame('MedTech Corp', $array['sponsor']);
        self::assertSame('planned', $array['status']);
        self::assertArrayNotHasKey('start_date', $array);
        self::assertArrayNotHasKey('end_date', $array);
        self::assertArrayNotHasKey('planned_subjects', $array);
        self::assertArrayNotHasKey('enrolled_subjects', $array);
        self::assertArrayNotHasKey('primary_endpoint', $array);
        self::assertArrayNotHasKey('investigation_plan', $array);
        self::assertArrayNotHasKey('ethics_committee_approvals', $array);
        self::assertArrayNotHasKey('competent_authority_notification', $array);
        self::assertArrayNotHasKey('clinical_evidence_summary', $array);
    }

    #[Test]
    public function toArrayFullRecord(): void
    {
        $ci = new ClinicalInvestigation(
            id: 'CI-002',
            deviceIdentifier: 'DI-501',
            title: 'Efficacy trial for blood glucose monitor',
            sponsor: 'DiabetesTech Inc',
            status: ClinicalInvestigationStatus::Completed,
            startDate: new DateTimeImmutable('2025-06-01'),
            endDate: new DateTimeImmutable('2026-02-28'),
            plannedSubjects: 200,
            enrolledSubjects: 195,
            primaryEndpoint: 'MARD < 10% vs reference analyzer',
            investigationPlan: 'CIP-2025-001 v3.0',
            ethicsCommitteeApprovals: ['EC-2025-042', 'IRB-2025-103'],
            competentAuthorityNotification: 'BFARM-2025-0042',
            clinicalEvidenceSummary: 'Primary endpoint met with MARD 8.7%',
        );

        $array = $ci->toArray();

        self::assertSame('completed', $array['status']);
        self::assertSame('2025-06-01', $array['start_date']);
        self::assertSame('2026-02-28', $array['end_date']);
        self::assertSame(200, $array['planned_subjects']);
        self::assertSame(195, $array['enrolled_subjects']);
        self::assertSame('MARD < 10% vs reference analyzer', $array['primary_endpoint']);
        self::assertSame('CIP-2025-001 v3.0', $array['investigation_plan']);
        self::assertSame(['EC-2025-042', 'IRB-2025-103'], $array['ethics_committee_approvals']);
        self::assertSame('BFARM-2025-0042', $array['competent_authority_notification']);
        self::assertSame('Primary endpoint met with MARD 8.7%', $array['clinical_evidence_summary']);
    }

    #[Test]
    public function allStatusCasesExist(): void
    {
        $cases = ClinicalInvestigationStatus::cases();

        self::assertCount(8, $cases);
        self::assertSame('planned', ClinicalInvestigationStatus::Planned->value);
        self::assertSame('submitted', ClinicalInvestigationStatus::Submitted->value);
        self::assertSame('approved', ClinicalInvestigationStatus::Approved->value);
        self::assertSame('recruiting', ClinicalInvestigationStatus::Recruiting->value);
        self::assertSame('active', ClinicalInvestigationStatus::Active->value);
        self::assertSame('completed', ClinicalInvestigationStatus::Completed->value);
        self::assertSame('terminated', ClinicalInvestigationStatus::Terminated->value);
        self::assertSame('suspended', ClinicalInvestigationStatus::Suspended->value);
    }

    #[Test]
    public function toArrayPartialOptionals(): void
    {
        $ci = new ClinicalInvestigation(
            id: 'CI-003',
            deviceIdentifier: 'DI-502',
            title: 'Pilot study',
            sponsor: 'ResearchLab',
            status: ClinicalInvestigationStatus::Recruiting,
            startDate: new DateTimeImmutable('2026-03-01'),
            plannedSubjects: 50,
        );

        $array = $ci->toArray();

        self::assertSame('2026-03-01', $array['start_date']);
        self::assertSame(50, $array['planned_subjects']);
        self::assertArrayNotHasKey('end_date', $array);
        self::assertArrayNotHasKey('enrolled_subjects', $array);
        self::assertArrayNotHasKey('primary_endpoint', $array);
    }
}
