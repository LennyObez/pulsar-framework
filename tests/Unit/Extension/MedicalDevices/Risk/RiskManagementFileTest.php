<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Risk;

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
        $hazards = [
            new HazardEntry(
                hazardId: 'H-001',
                hazardDescription: 'Electrical shock',
                harmDescription: 'Burns',
                severity: RiskSeverity::Critical,
                probability: RiskProbability::Remote,
                initialRiskLevel: RiskLevel::High,
                riskControls: ['Insulation added'],
                residualRiskLevel: RiskLevel::Low,
            ),
            new HazardEntry(
                hazardId: 'H-002',
                hazardDescription: 'Overheating',
                harmDescription: 'Tissue damage',
                severity: RiskSeverity::Serious,
                probability: RiskProbability::Occasional,
                initialRiskLevel: RiskLevel::High,
                riskControls: ['Thermal cutoff'],
                residualRiskLevel: RiskLevel::Medium,
            ),
            new HazardEntry(
                hazardId: 'H-003',
                hazardDescription: 'Sharp edge',
                harmDescription: 'Laceration',
                severity: RiskSeverity::Minor,
                probability: RiskProbability::Improbable,
                initialRiskLevel: RiskLevel::Low,
                riskControls: ['Edge guard'],
                residualRiskLevel: RiskLevel::Acceptable,
            ),
        ];

        $file = new RiskManagementFile(
            id: 'RMF-001',
            deviceIdentifier: 'DI-001',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2025-01-01'),
            hazards: $hazards,
        );

        $summary = $file->hazardSummary();

        self::assertSame(0, $summary['high']);
        self::assertSame(1, $summary['medium']);
        self::assertSame(1, $summary['low']);
        self::assertSame(1, $summary['acceptable']);
    }

    #[Test]
    public function hazardSummaryReturnsZerosWhenNoHazards(): void
    {
        $file = new RiskManagementFile(
            id: 'RMF-001',
            deviceIdentifier: 'DI-001',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2025-01-01'),
        );

        $summary = $file->hazardSummary();

        self::assertSame(['high' => 0, 'medium' => 0, 'low' => 0, 'acceptable' => 0], $summary);
    }

    #[Test]
    public function toArrayIncludesHazardCountAndSummary(): void
    {
        $hazard = new HazardEntry(
            hazardId: 'H-001',
            hazardDescription: 'Test hazard',
            harmDescription: 'Test harm',
            severity: RiskSeverity::Negligible,
            probability: RiskProbability::Incredible,
            initialRiskLevel: RiskLevel::Low,
        );

        $file = new RiskManagementFile(
            id: 'RMF-001',
            deviceIdentifier: 'DI-001',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2025-01-01'),
            hazards: [$hazard],
        );

        $data = $file->toArray();

        self::assertSame(1, $data['hazard_count']);
        self::assertIsArray($data['hazard_summary']);
        self::assertIsArray($data['hazards']);
        self::assertCount(1, $data['hazards']);
    }

    #[Test]
    public function toArrayOmitsNullOptionalFields(): void
    {
        $file = new RiskManagementFile(
            id: 'RMF-001',
            deviceIdentifier: 'DI-001',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2025-01-01'),
        );

        $data = $file->toArray();

        self::assertArrayNotHasKey('last_review_date', $data);
        self::assertArrayNotHasKey('risk_management_plan', $data);
        self::assertArrayNotHasKey('hazards', $data);
        self::assertArrayNotHasKey('overall_residual_risk_acceptability', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $file = new RiskManagementFile(
            id: 'RMF-001',
            deviceIdentifier: 'DI-001',
            deviceName: 'Test Device',
            createdAt: new DateTimeImmutable('2025-01-01'),
            lastReviewDate: new DateTimeImmutable('2025-06-01'),
            riskManagementPlan: 'Plan document reference',
            overallResidualRiskAcceptability: 'Acceptable per benefit-risk analysis',
            benefitRiskAnalysis: 'Benefits outweigh risks',
        );

        $data = $file->toArray();

        self::assertSame('2025-06-01', $data['last_review_date']);
        self::assertSame('Plan document reference', $data['risk_management_plan']);
        self::assertSame('Acceptable per benefit-risk analysis', $data['overall_residual_risk_acceptability']);
        self::assertSame('Benefits outweigh risks', $data['benefit_risk_analysis']);
    }
}
