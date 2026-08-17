<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\ThirdParty;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\ThirdParty\ConcentrationRiskResult;
use Pulsar\Extension\Dora\ThirdParty\ThirdPartyProvider;
use Pulsar\Extension\Dora\ThirdParty\ThirdPartyRiskLevel;

#[CoversClass(ThirdPartyProvider::class)]
#[CoversClass(ConcentrationRiskResult::class)]
final class ThirdPartyProviderTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $provider = new ThirdPartyProvider(
            id: 'TP-001',
            name: 'CloudCo',
            jurisdiction: 'EU',
            riskLevel: ThirdPartyRiskLevel::Medium,
            supportsCriticalFunctions: false,
        );

        self::assertSame('TP-001', $provider->id);
        self::assertSame('CloudCo', $provider->name);
        self::assertSame('EU', $provider->jurisdiction);
        self::assertSame(ThirdPartyRiskLevel::Medium, $provider->riskLevel);
        self::assertFalse($provider->supportsCriticalFunctions);
        self::assertSame([], $provider->servicesProvided);
        self::assertNull($provider->contractStartDate);
        self::assertNull($provider->exitStrategy);
        self::assertSame([], $provider->subcontractors);
    }

    #[Test]
    public function toArrayWithMinimalProvider(): void
    {
        $provider = new ThirdPartyProvider(
            id: 'TP-002',
            name: 'AuthProvider',
            jurisdiction: 'US',
            riskLevel: ThirdPartyRiskLevel::Low,
            supportsCriticalFunctions: false,
        );

        $array = $provider->toArray();

        self::assertSame('TP-002', $array['id']);
        self::assertSame('AuthProvider', $array['name']);
        self::assertSame('US', $array['jurisdiction']);
        self::assertSame('low', $array['risk_level']);
        self::assertFalse($array['supports_critical_functions']);
        self::assertArrayNotHasKey('services_provided', $array);
        self::assertArrayNotHasKey('contract_start_date', $array);
        self::assertArrayNotHasKey('exit_strategy', $array);
        self::assertArrayNotHasKey('subcontractors', $array);
    }

    #[Test]
    public function toArrayWithFullProvider(): void
    {
        $start = new DateTimeImmutable('2024-01-01');
        $end = new DateTimeImmutable('2026-12-31');
        $audit = new DateTimeImmutable('2025-06-15');

        $provider = new ThirdPartyProvider(
            id: 'TP-003',
            name: 'MegaCloud',
            jurisdiction: 'EU',
            riskLevel: ThirdPartyRiskLevel::Critical,
            supportsCriticalFunctions: true,
            servicesProvided: ['compute', 'storage', 'CDN'],
            contractStartDate: $start,
            contractEndDate: $end,
            lastAuditDate: $audit,
            exitStrategy: 'Multi-cloud migration plan with 6-month runway',
            subcontractors: ['SubCo A', 'SubCo B'],
            dataProcessingLocation: 'Frankfurt, DE',
        );

        $array = $provider->toArray();

        self::assertSame(['compute', 'storage', 'CDN'], $array['services_provided']);
        self::assertSame('2024-01-01', $array['contract_start_date']);
        self::assertSame('2026-12-31', $array['contract_end_date']);
        self::assertSame('2025-06-15', $array['last_audit_date']);
        self::assertSame('Multi-cloud migration plan with 6-month runway', $array['exit_strategy']);
        self::assertSame(['SubCo A', 'SubCo B'], $array['subcontractors']);
        self::assertSame('Frankfurt, DE', $array['data_processing_location']);
        self::assertTrue($array['supports_critical_functions']);
    }

    #[Test]
    public function concentrationRiskResultWithoutMitigation(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'MegaCloud',
            dependentServiceCount: 12,
            criticalFunctions: ['payments', 'auth', 'data-store'],
            isConcentrationRisk: true,
        );

        self::assertTrue($result->isConcentrationRisk);
        self::assertSame(12, $result->dependentServiceCount);
        self::assertCount(3, $result->criticalFunctions);
        self::assertNull($result->mitigationRecommendation);

        $array = $result->toArray();
        self::assertSame('MegaCloud', $array['provider_name']);
        self::assertArrayNotHasKey('mitigation_recommendation', $array);
    }

    #[Test]
    public function concentrationRiskResultWithMitigation(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'SmallVendor',
            dependentServiceCount: 2,
            criticalFunctions: [],
            isConcentrationRisk: false,
            mitigationRecommendation: 'No action needed',
        );

        self::assertFalse($result->isConcentrationRisk);
        $array = $result->toArray();
        self::assertSame('No action needed', $array['mitigation_recommendation']);
    }

    /**
     * @return iterable<string, array{ThirdPartyRiskLevel, string}>
     */
    public static function riskLevelProvider(): iterable
    {
        yield 'critical' => [ThirdPartyRiskLevel::Critical, 'critical'];
        yield 'high' => [ThirdPartyRiskLevel::High, 'high'];
        yield 'medium' => [ThirdPartyRiskLevel::Medium, 'medium'];
        yield 'low' => [ThirdPartyRiskLevel::Low, 'low'];
    }

    #[Test]
    #[DataProvider('riskLevelProvider')]
    public function riskLevelEnumHasExpectedValue(ThirdPartyRiskLevel $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }
}
