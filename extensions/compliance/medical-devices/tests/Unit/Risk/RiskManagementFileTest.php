<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Risk;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Risk\HazardEntry;
use Pulsar\Extension\MedicalDevices\Risk\RiskLevel;
use Pulsar\Extension\MedicalDevices\Risk\RiskManagementFile;
use Pulsar\Extension\MedicalDevices\Risk\RiskProbability;
use Pulsar\Extension\MedicalDevices\Risk\RiskSeverity;

#[CoversClass(RiskManagementFile::class)]
final class RiskManagementFileTest extends TestCase
{
    #[Test]
    public function hazardSummaryCountsByResidualRiskLevel(): void
    {
        $file = new RiskManagementFile(
            id: 'rmf-1',
            deviceIdentifier: 'UDI-001',
            deviceName: 'Cardiac Monitor',
            createdAt: new DateTimeImmutable('2026-01-01'),
            hazards: [
                new HazardEntry('H-1', 'Electrical shock', 'Burns', RiskSeverity::Critical, RiskProbability::Remote, RiskLevel::High, residualRiskLevel: RiskLevel::High),
                new HazardEntry('H-2', 'Software error', 'Misdiagnosis', RiskSeverity::Serious, RiskProbability::Occasional, RiskLevel::Medium, ['Input validation'], RiskLevel::Low),
                new HazardEntry('H-3', 'Battery leak', 'Chemical burn', RiskSeverity::Minor, RiskProbability::Improbable, RiskLevel::Low, ['Sealed housing'], RiskLevel::Acceptable),
                new HazardEntry('H-4', 'Signal drift', 'Inaccurate reading', RiskSeverity::Serious, RiskProbability::Remote, RiskLevel::Medium, ['Auto-calibration'], RiskLevel::Low),
            ],
        );

        $summary = $file->hazardSummary();

        self::assertSame(1, $summary['high']);
        self::assertSame(0, $summary['medium']);
        self::assertSame(2, $summary['low']);
        self::assertSame(1, $summary['acceptable']);
    }

    #[Test]
    public function hazardSummaryWithNoHazards(): void
    {
        $file = new RiskManagementFile(
            id: 'rmf-2',
            deviceIdentifier: 'UDI-002',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2026-01-01'),
        );

        $summary = $file->hazardSummary();

        self::assertSame(['high' => 0, 'medium' => 0, 'low' => 0, 'acceptable' => 0], $summary);
    }

    #[Test]
    public function toArrayMinimal(): void
    {
        $file = new RiskManagementFile(
            id: 'rmf-3',
            deviceIdentifier: 'UDI-003',
            deviceName: 'Pulse Oximeter',
            createdAt: new DateTimeImmutable('2026-03-15'),
        );

        $array = $file->toArray();

        self::assertSame('rmf-3', $array['id']);
        self::assertSame('UDI-003', $array['device_identifier']);
        self::assertSame('Pulse Oximeter', $array['device_name']);
        self::assertSame('2026-03-15', $array['created_at']);
        self::assertSame(0, $array['hazard_count']);
        self::assertSame(['high' => 0, 'medium' => 0, 'low' => 0, 'acceptable' => 0], $array['hazard_summary']);
        self::assertArrayNotHasKey('last_review_date', $array);
        self::assertArrayNotHasKey('risk_management_plan', $array);
        self::assertArrayNotHasKey('hazards', $array);
        self::assertArrayNotHasKey('overall_residual_risk_acceptability', $array);
    }

    #[Test]
    public function toArrayFull(): void
    {
        $hazard = new HazardEntry(
            'H-1',
            'Overheating',
            'Tissue damage',
            RiskSeverity::Critical,
            RiskProbability::Remote,
            RiskLevel::High,
            ['Thermal cutoff', 'Insulation'],
            RiskLevel::Low,
            'Controls verified via bench testing',
        );

        $file = new RiskManagementFile(
            id: 'rmf-4',
            deviceIdentifier: 'UDI-004',
            deviceName: 'Laser Scalpel',
            createdAt: new DateTimeImmutable('2025-06-01'),
            lastReviewDate: new DateTimeImmutable('2026-03-01'),
            riskManagementPlan: 'RMP-2025-001',
            hazards: [$hazard],
            overallResidualRiskAcceptability: 'acceptable',
            benefitRiskAnalysis: 'Benefits outweigh residual risks',
        );

        $array = $file->toArray();

        self::assertSame('2026-03-01', $array['last_review_date']);
        self::assertSame('RMP-2025-001', $array['risk_management_plan']);
        self::assertSame(1, $array['hazard_count']);
        self::assertCount(1, $array['hazards']);
        self::assertSame('H-1', $array['hazards'][0]['hazard_id']);
        self::assertSame('acceptable', $array['overall_residual_risk_acceptability']);
        self::assertSame('Benefits outweigh residual risks', $array['benefit_risk_analysis']);
    }
}
