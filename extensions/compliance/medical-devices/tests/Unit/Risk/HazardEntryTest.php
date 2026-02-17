<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Risk\HazardEntry;
use Pulsar\Extension\MedicalDevices\Risk\RiskLevel;
use Pulsar\Extension\MedicalDevices\Risk\RiskProbability;
use Pulsar\Extension\MedicalDevices\Risk\RiskSeverity;

#[CoversClass(HazardEntry::class)]
#[CoversClass(RiskLevel::class)]
#[CoversClass(RiskSeverity::class)]
#[CoversClass(RiskProbability::class)]
final class HazardEntryTest extends TestCase
{
    #[Test]
    public function minimalHazardDefaultsToAcceptableResidual(): void
    {
        $entry = new HazardEntry(
            hazardId: 'HAZ-001',
            hazardDescription: 'Electrical shock from exposed contacts',
            harmDescription: 'Burns or cardiac arrest',
            severity: RiskSeverity::Critical,
            probability: RiskProbability::Remote,
            initialRiskLevel: RiskLevel::High,
        );

        self::assertSame(RiskLevel::Acceptable, $entry->residualRiskLevel);
        self::assertSame([], $entry->riskControls);
        self::assertNull($entry->verificationOfControlEffectiveness);
    }

    #[Test]
    public function toArrayWithMinimalEntry(): void
    {
        $entry = new HazardEntry(
            hazardId: 'HAZ-002',
            hazardDescription: 'Material biocompatibility',
            harmDescription: 'Allergic reaction',
            severity: RiskSeverity::Minor,
            probability: RiskProbability::Improbable,
            initialRiskLevel: RiskLevel::Low,
        );

        $array = $entry->toArray();

        self::assertSame('HAZ-002', $array['hazard_id']);
        self::assertSame('Material biocompatibility', $array['hazard_description']);
        self::assertSame('Allergic reaction', $array['harm_description']);
        self::assertSame('minor', $array['severity']);
        self::assertSame('improbable', $array['probability']);
        self::assertSame('low', $array['initial_risk_level']);
        self::assertSame('acceptable', $array['residual_risk_level']);
        self::assertArrayNotHasKey('risk_controls', $array);
        self::assertArrayNotHasKey('verification_of_control_effectiveness', $array);
    }

    #[Test]
    public function toArrayWithRiskControlsAndVerification(): void
    {
        $entry = new HazardEntry(
            hazardId: 'HAZ-003',
            hazardDescription: 'Sensor inaccuracy',
            harmDescription: 'Misdiagnosis',
            severity: RiskSeverity::Serious,
            probability: RiskProbability::Occasional,
            initialRiskLevel: RiskLevel::High,
            riskControls: ['Dual sensor redundancy', 'Automated calibration check'],
            residualRiskLevel: RiskLevel::Low,
            verificationOfControlEffectiveness: 'V&V protocol VER-2026-001',
        );

        $array = $entry->toArray();

        self::assertSame(['Dual sensor redundancy', 'Automated calibration check'], $array['risk_controls']);
        self::assertSame('low', $array['residual_risk_level']);
        self::assertSame('V&V protocol VER-2026-001', $array['verification_of_control_effectiveness']);
    }

    /**
     * @return iterable<string, array{RiskSeverity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'negligible' => [RiskSeverity::Negligible, 'negligible'];
        yield 'minor' => [RiskSeverity::Minor, 'minor'];
        yield 'serious' => [RiskSeverity::Serious, 'serious'];
        yield 'critical' => [RiskSeverity::Critical, 'critical'];
        yield 'catastrophic' => [RiskSeverity::Catastrophic, 'catastrophic'];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function riskSeverityValues(RiskSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    /**
     * @return iterable<string, array{RiskProbability, string}>
     */
    public static function probabilityProvider(): iterable
    {
        yield 'incredible' => [RiskProbability::Incredible, 'incredible'];
        yield 'improbable' => [RiskProbability::Improbable, 'improbable'];
        yield 'remote' => [RiskProbability::Remote, 'remote'];
        yield 'occasional' => [RiskProbability::Occasional, 'occasional'];
        yield 'probable' => [RiskProbability::Probable, 'probable'];
        yield 'frequent' => [RiskProbability::Frequent, 'frequent'];
    }

    #[Test]
    #[DataProvider('probabilityProvider')]
    public function riskProbabilityValues(RiskProbability $probability, string $expected): void
    {
        self::assertSame($expected, $probability->value);
    }

    /**
     * @return iterable<string, array{RiskLevel, string}>
     */
    public static function riskLevelProvider(): iterable
    {
        yield 'acceptable' => [RiskLevel::Acceptable, 'acceptable'];
        yield 'low' => [RiskLevel::Low, 'low'];
        yield 'medium' => [RiskLevel::Medium, 'medium'];
        yield 'high' => [RiskLevel::High, 'high'];
    }

    #[Test]
    #[DataProvider('riskLevelProvider')]
    public function riskLevelValues(RiskLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }
}
