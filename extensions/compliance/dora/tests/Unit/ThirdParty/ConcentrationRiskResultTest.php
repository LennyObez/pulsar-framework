<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\ThirdParty;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\ThirdParty\ConcentrationRiskResult;

#[CoversClass(ConcentrationRiskResult::class)]
final class ConcentrationRiskResultTest extends TestCase
{
    #[Test]
    public function toArrayWithoutMitigation(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'CloudProvider Inc',
            dependentServiceCount: 5,
            criticalFunctions: ['payments', 'authentication', 'data-storage'],
            isConcentrationRisk: true,
        );

        $array = $result->toArray();

        self::assertSame('CloudProvider Inc', $array['provider_name']);
        self::assertSame(5, $array['dependent_service_count']);
        self::assertSame(['payments', 'authentication', 'data-storage'], $array['critical_functions']);
        self::assertTrue($array['is_concentration_risk']);
        self::assertArrayNotHasKey('mitigation_recommendation', $array);
    }

    #[Test]
    public function toArrayWithMitigation(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'SingleVendor Ltd',
            dependentServiceCount: 8,
            criticalFunctions: ['core-banking'],
            isConcentrationRisk: true,
            mitigationRecommendation: 'Establish secondary provider for core banking operations',
        );

        $array = $result->toArray();

        self::assertSame('Establish secondary provider for core banking operations', $array['mitigation_recommendation']);
    }

    #[Test]
    public function noConcentrationRisk(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'MinorVendor',
            dependentServiceCount: 1,
            criticalFunctions: [],
            isConcentrationRisk: false,
        );

        $array = $result->toArray();

        self::assertFalse($array['is_concentration_risk']);
        self::assertSame([], $array['critical_functions']);
        self::assertSame(1, $array['dependent_service_count']);
    }
}
