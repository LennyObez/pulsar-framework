<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Clinical;

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
    public function constructsWithRequiredFields(): void
    {
        $investigation = new ClinicalInvestigation(
            id: 'CI-001',
            deviceIdentifier: 'DI-001',
            title: 'Safety and Performance Study of Cardiac Monitor X100',
            sponsor: 'MedTech Corp',
            status: ClinicalInvestigationStatus::Planned,
        );

        self::assertSame('CI-001', $investigation->id);
        self::assertSame(ClinicalInvestigationStatus::Planned, $investigation->status);
        self::assertNull($investigation->startDate);
        self::assertNull($investigation->plannedSubjects);
        self::assertSame([], $investigation->ethicsCommitteeApprovals);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $investigation = new ClinicalInvestigation(
            id: 'CI-001',
            deviceIdentifier: 'DI-001',
            title: 'Study Title',
            sponsor: 'Sponsor',
            status: ClinicalInvestigationStatus::Planned,
        );

        $data = $investigation->toArray();

        self::assertSame('CI-001', $data['id']);
        self::assertSame('planned', $data['status']);
        self::assertArrayNotHasKey('start_date', $data);
        self::assertArrayNotHasKey('planned_subjects', $data);
        self::assertArrayNotHasKey('ethics_committee_approvals', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $investigation = new ClinicalInvestigation(
            id: 'CI-002',
            deviceIdentifier: 'DI-002',
            title: 'Pivotal Trial',
            sponsor: 'OrthoMed',
            status: ClinicalInvestigationStatus::Active,
            startDate: new DateTimeImmutable('2025-01-15'),
            endDate: new DateTimeImmutable('2026-01-15'),
            plannedSubjects: 200,
            enrolledSubjects: 150,
            primaryEndpoint: 'Pain reduction at 6 months',
            investigationPlan: 'Randomized double-blind controlled trial',
            ethicsCommitteeApprovals: ['EC-2024-001', 'EC-2024-002'],
            competentAuthorityNotification: 'CA-NOT-2024-001',
            clinicalEvidenceSummary: 'Interim results show significant improvement',
        );

        $data = $investigation->toArray();

        self::assertSame('2025-01-15', $data['start_date']);
        self::assertSame('2026-01-15', $data['end_date']);
        self::assertSame(200, $data['planned_subjects']);
        self::assertSame(150, $data['enrolled_subjects']);
        self::assertSame('Pain reduction at 6 months', $data['primary_endpoint']);
        self::assertIsArray($data['ethics_committee_approvals']);
        self::assertCount(2, $data['ethics_committee_approvals']);
        self::assertSame('CA-NOT-2024-001', $data['competent_authority_notification']);
    }

    #[Test]
    public function statusTransitionsReflectLifecycle(): void
    {
        $statuses = ClinicalInvestigationStatus::cases();

        self::assertCount(8, $statuses);
        self::assertSame('planned', ClinicalInvestigationStatus::Planned->value);
        self::assertSame('terminated', ClinicalInvestigationStatus::Terminated->value);
    }
}
