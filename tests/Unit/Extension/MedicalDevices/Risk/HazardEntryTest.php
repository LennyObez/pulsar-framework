<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Risk\HazardEntry;
use Pulsar\Extension\MedicalDevices\Risk\RiskLevel;
use Pulsar\Extension\MedicalDevices\Risk\RiskProbability;
use Pulsar\Extension\MedicalDevices\Risk\RiskSeverity;

#[CoversClass(HazardEntry::class)]
final class HazardEntryTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $entry = new HazardEntry(
            hazardId: 'H-001',
            hazardDescription: 'Electrical shock from exposed conductor',
            harmDescription: 'Burns, cardiac arrest',
            severity: RiskSeverity::Catastrophic,
            probability: RiskProbability::Remote,
            initialRiskLevel: RiskLevel::High,
        );

        self::assertSame(RiskLevel::Acceptable, $entry->residualRiskLevel);
        self::assertSame([], $entry->riskControls);
        self::assertNull($entry->verificationOfControlEffectiveness);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $entry = new HazardEntry(
            hazardId: 'H-001',
            hazardDescription: 'Overheating',
            harmDescription: 'Tissue damage',
            severity: RiskSeverity::Serious,
            probability: RiskProbability::Occasional,
            initialRiskLevel: RiskLevel::High,
            residualRiskLevel: RiskLevel::Low,
        );

        $data = $entry->toArray();

        self::assertSame('H-001', $data['hazard_id']);
        self::assertSame('Overheating', $data['hazard_description']);
        self::assertSame('serious', $data['severity']);
        self::assertSame('occasional', $data['probability']);
        self::assertSame('high', $data['initial_risk_level']);
        self::assertSame('low', $data['residual_risk_level']);
    }

    #[Test]
    public function toArrayOmitsEmptyControlsAndNullVerification(): void
    {
        $entry = new HazardEntry(
            hazardId: 'H-001',
            hazardDescription: 'Test',
            harmDescription: 'Test',
            severity: RiskSeverity::Minor,
            probability: RiskProbability::Improbable,
            initialRiskLevel: RiskLevel::Low,
        );

        $data = $entry->toArray();

        self::assertArrayNotHasKey('risk_controls', $data);
        self::assertArrayNotHasKey('verification_of_control_effectiveness', $data);
    }

    #[Test]
    public function toArrayIncludesControlsAndVerification(): void
    {
        $entry = new HazardEntry(
            hazardId: 'H-001',
            hazardDescription: 'Sharp edge',
            harmDescription: 'Cut',
            severity: RiskSeverity::Minor,
            probability: RiskProbability::Probable,
            initialRiskLevel: RiskLevel::Medium,
            riskControls: ['Edge guard installed', 'Warning label added'],
            residualRiskLevel: RiskLevel::Low,
            verificationOfControlEffectiveness: 'Testing confirmed edge guard prevents contact',
        );

        $data = $entry->toArray();

        self::assertIsArray($data['risk_controls']);
        self::assertCount(2, $data['risk_controls']);
        self::assertSame('Testing confirmed edge guard prevents contact', $data['verification_of_control_effectiveness']);
    }

    #[Test]
    public function riskSeverityEnumHasAllCases(): void
    {
        self::assertCount(5, RiskSeverity::cases());
        self::assertSame('negligible', RiskSeverity::Negligible->value);
        self::assertSame('catastrophic', RiskSeverity::Catastrophic->value);
    }

    #[Test]
    public function riskProbabilityEnumHasAllCases(): void
    {
        self::assertCount(6, RiskProbability::cases());
        self::assertSame('incredible', RiskProbability::Incredible->value);
        self::assertSame('frequent', RiskProbability::Frequent->value);
    }

    #[Test]
    public function riskLevelEnumHasAllCases(): void
    {
        self::assertCount(4, RiskLevel::cases());
        self::assertSame('acceptable', RiskLevel::Acceptable->value);
        self::assertSame('high', RiskLevel::High->value);
    }
}
