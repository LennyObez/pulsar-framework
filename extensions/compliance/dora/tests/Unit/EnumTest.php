<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit;

use BackedEnum;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Incident\IctIncidentClassification;
use Pulsar\Extension\Dora\Incident\IncidentReportingPhase;
use Pulsar\Extension\Dora\Risk\IctAssetCriticality;
use Pulsar\Extension\Dora\Risk\IctRiskCategory;
use Pulsar\Extension\Dora\Sharing\ThreatSeverity;
use Pulsar\Extension\Dora\Testing\ResilienceTestType;
use Pulsar\Extension\Dora\ThirdParty\ThirdPartyRiskLevel;

#[CoversNothing]
final class EnumTest extends TestCase
{
    #[Test]
    public function ictIncidentClassificationCases(): void
    {
        $cases = IctIncidentClassification::cases();
        self::assertCount(2, $cases);
        self::assertSame('major', IctIncidentClassification::Major->value);
        self::assertSame('non_major', IctIncidentClassification::NonMajor->value);
    }

    #[Test]
    public function incidentReportingPhaseCases(): void
    {
        $cases = IncidentReportingPhase::cases();
        self::assertCount(5, $cases);
        self::assertSame('detection', IncidentReportingPhase::Detection->value);
        self::assertSame('initial_notification', IncidentReportingPhase::InitialNotification->value);
        self::assertSame('intermediate_report', IncidentReportingPhase::IntermediateReport->value);
        self::assertSame('final_report', IncidentReportingPhase::FinalReport->value);
        self::assertSame('closed', IncidentReportingPhase::Closed->value);
    }

    #[Test]
    public function ictAssetCriticalityCases(): void
    {
        $cases = IctAssetCriticality::cases();
        self::assertCount(4, $cases);
        self::assertSame('critical', IctAssetCriticality::Critical->value);
        self::assertSame('important', IctAssetCriticality::Important->value);
        self::assertSame('standard', IctAssetCriticality::Standard->value);
        self::assertSame('low', IctAssetCriticality::Low->value);
    }

    #[Test]
    public function ictRiskCategoryCases(): void
    {
        $cases = IctRiskCategory::cases();
        self::assertCount(8, $cases);
        self::assertSame('cyber_attack', IctRiskCategory::CyberAttack->value);
        self::assertSame('supply_chain', IctRiskCategory::SupplyChain->value);
        self::assertSame('concentration_risk', IctRiskCategory::ConcentrationRisk->value);
    }

    #[Test]
    public function threatSeverityCases(): void
    {
        $cases = ThreatSeverity::cases();
        self::assertCount(5, $cases);
        self::assertSame('critical', ThreatSeverity::Critical->value);
        self::assertSame('informational', ThreatSeverity::Informational->value);
    }

    #[Test]
    public function resilienceTestTypeCases(): void
    {
        $cases = ResilienceTestType::cases();
        self::assertCount(11, $cases);
        self::assertSame('vulnerability_scanning', ResilienceTestType::VulnerabilityScanning->value);
        self::assertSame('penetration_testing', ResilienceTestType::PenetrationTesting->value);
        self::assertSame('tlpt', ResilienceTestType::Tlpt->value);
    }

    #[Test]
    public function thirdPartyRiskLevelCases(): void
    {
        $cases = ThirdPartyRiskLevel::cases();
        self::assertCount(4, $cases);
        self::assertSame('critical', ThirdPartyRiskLevel::Critical->value);
        self::assertSame('low', ThirdPartyRiskLevel::Low->value);
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, string}>
     */
    public static function allEnumFromProvider(): iterable
    {
        yield 'IctIncidentClassification::Major' => [IctIncidentClassification::class, 'major'];
        yield 'IncidentReportingPhase::Detection' => [IncidentReportingPhase::class, 'detection'];
        yield 'IctAssetCriticality::Critical' => [IctAssetCriticality::class, 'critical'];
        yield 'IctRiskCategory::CyberAttack' => [IctRiskCategory::class, 'cyber_attack'];
        yield 'ThreatSeverity::High' => [ThreatSeverity::class, 'high'];
        yield 'ResilienceTestType::GapAnalysis' => [ResilienceTestType::class, 'gap_analysis'];
        yield 'ThirdPartyRiskLevel::Medium' => [ThirdPartyRiskLevel::class, 'medium'];
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    #[Test]
    #[DataProvider('allEnumFromProvider')]
    public function allEnumsCanBeInstantiatedFromValue(string $enumClass, string $value): void
    {
        $instance = $enumClass::from($value);

        self::assertSame($value, $instance->value);
    }
}
