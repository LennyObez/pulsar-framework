<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Risk\IctAsset;
use Pulsar\Extension\Dora\Risk\IctAssetCriticality;
use Pulsar\Extension\Dora\Risk\IctRiskCategory;

#[CoversClass(IctAsset::class)]
#[CoversClass(IctAssetCriticality::class)]
#[CoversClass(IctRiskCategory::class)]
final class IctAssetTest extends TestCase
{
    #[Test]
    public function minimalConstructorSetsDefaults(): void
    {
        $asset = new IctAsset(
            id: 'ASSET-001',
            name: 'Core Banking API',
            type: 'application',
            owner: 'IT Operations',
            criticality: IctAssetCriticality::Critical,
        );

        self::assertSame('ASSET-001', $asset->id);
        self::assertSame('Core Banking API', $asset->name);
        self::assertSame(IctAssetCriticality::Critical, $asset->criticality);
        self::assertSame([], $asset->dependencies);
        self::assertSame([], $asset->dataFlows);
        self::assertNull($asset->thirdPartyProvider);
        self::assertNull($asset->location);
        self::assertNull($asset->recoveryTimeObjective);
        self::assertNull($asset->recoveryPointObjective);
    }

    #[Test]
    public function toArrayWithMinimalAsset(): void
    {
        $asset = new IctAsset(
            id: 'ASSET-002',
            name: 'Auth Service',
            type: 'service',
            owner: 'Security',
            criticality: IctAssetCriticality::Important,
        );

        $array = $asset->toArray();

        self::assertSame('ASSET-002', $array['id']);
        self::assertSame('Auth Service', $array['name']);
        self::assertSame('service', $array['type']);
        self::assertSame('Security', $array['owner']);
        self::assertSame('important', $array['criticality']);
        self::assertArrayNotHasKey('dependencies', $array);
        self::assertArrayNotHasKey('third_party_provider', $array);
        self::assertArrayNotHasKey('location', $array);
    }

    #[Test]
    public function toArrayWithFullAsset(): void
    {
        $asset = new IctAsset(
            id: 'ASSET-003',
            name: 'Payment Gateway',
            type: 'integration',
            owner: 'Payments Team',
            criticality: IctAssetCriticality::Critical,
            dependencies: ['ASSET-001', 'ASSET-002'],
            dataFlows: ['Customer PII to payment processor'],
            thirdPartyProvider: 'Stripe',
            location: 'EU-West-1',
            recoveryTimeObjective: '1 hour',
            recoveryPointObjective: '5 minutes',
        );

        $array = $asset->toArray();

        self::assertSame(['ASSET-001', 'ASSET-002'], $array['dependencies']);
        self::assertSame(['Customer PII to payment processor'], $array['data_flows']);
        self::assertSame('Stripe', $array['third_party_provider']);
        self::assertSame('EU-West-1', $array['location']);
        self::assertSame('1 hour', $array['recovery_time_objective']);
        self::assertSame('5 minutes', $array['recovery_point_objective']);
    }

    /**
     * @return iterable<string, array{IctAssetCriticality, string}>
     */
    public static function criticalityProvider(): iterable
    {
        yield 'critical' => [IctAssetCriticality::Critical, 'critical'];
        yield 'important' => [IctAssetCriticality::Important, 'important'];
        yield 'standard' => [IctAssetCriticality::Standard, 'standard'];
        yield 'low' => [IctAssetCriticality::Low, 'low'];
    }

    #[Test]
    #[DataProvider('criticalityProvider')]
    public function criticalityEnumHasExpectedValue(IctAssetCriticality $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{IctRiskCategory, string}>
     */
    public static function riskCategoryProvider(): iterable
    {
        yield 'cyber_attack' => [IctRiskCategory::CyberAttack, 'cyber_attack'];
        yield 'system_failure' => [IctRiskCategory::SystemFailure, 'system_failure'];
        yield 'third_party_dependency' => [IctRiskCategory::ThirdPartyDependency, 'third_party_dependency'];
        yield 'data_breach' => [IctRiskCategory::DataBreach, 'data_breach'];
        yield 'insider_threat' => [IctRiskCategory::InsiderThreat, 'insider_threat'];
        yield 'natural_disaster' => [IctRiskCategory::NaturalDisaster, 'natural_disaster'];
        yield 'supply_chain' => [IctRiskCategory::SupplyChain, 'supply_chain'];
        yield 'concentration_risk' => [IctRiskCategory::ConcentrationRisk, 'concentration_risk'];
    }

    #[Test]
    #[DataProvider('riskCategoryProvider')]
    public function riskCategoryEnumHasExpectedValue(IctRiskCategory $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }
}
