<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\ThirdParty;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\ThirdParty\ConcentrationRiskResult;

#[CoversClass(ConcentrationRiskResult::class)]
final class ConcentrationRiskResultTest extends TestCase
{
    #[Test]
    public function toArrayWithConcentrationRisk(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'Major Cloud Provider',
            dependentServiceCount: 5,
            criticalFunctions: ['Payments', 'Trading', 'Risk Management'],
            isConcentrationRisk: true,
            mitigationRecommendation: 'Implement multi-cloud strategy',
        );

        /** @var array<string, mixed> $data */
        $data = $result->toArray();

        self::assertSame('Major Cloud Provider', $data['provider_name']);
        self::assertSame(5, $data['dependent_service_count']);
        /** @var list<string> $functions */
        $functions = $data['critical_functions'];
        self::assertCount(3, $functions);
        self::assertTrue($data['is_concentration_risk']);
        self::assertSame('Implement multi-cloud strategy', $data['mitigation_recommendation']);
    }

    #[Test]
    public function toArrayWithoutConcentrationRisk(): void
    {
        $result = new ConcentrationRiskResult(
            providerName: 'Small Provider',
            dependentServiceCount: 1,
            criticalFunctions: [],
            isConcentrationRisk: false,
        );

        $data = $result->toArray();

        self::assertFalse($data['is_concentration_risk']);
        self::assertArrayNotHasKey('mitigation_recommendation', $data);
    }
}
