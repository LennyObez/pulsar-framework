<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Risk\IctAsset;
use Pulsar\Extension\Dora\Risk\IctAssetCriticality;

#[CoversClass(IctAsset::class)]
final class IctAssetTest extends TestCase
{
    #[Test]
    public function constructsWithRequiredFields(): void
    {
        $asset = new IctAsset(
            id: 'ICT-001',
            name: 'Core Banking System',
            type: 'application',
            owner: 'IT Operations',
            criticality: IctAssetCriticality::Critical,
        );

        self::assertSame('ICT-001', $asset->id);
        self::assertSame(IctAssetCriticality::Critical, $asset->criticality);
        self::assertSame([], $asset->dependencies);
        self::assertNull($asset->thirdPartyProvider);
    }

    #[Test]
    public function toArrayOmitsNullFields(): void
    {
        $asset = new IctAsset(
            id: 'ICT-001',
            name: 'Payment Gateway',
            type: 'service',
            owner: 'Payments Team',
            criticality: IctAssetCriticality::Important,
        );

        $data = $asset->toArray();

        self::assertSame('ICT-001', $data['id']);
        self::assertSame('important', $data['criticality']);
        self::assertArrayNotHasKey('dependencies', $data);
        self::assertArrayNotHasKey('third_party_provider', $data);
        self::assertArrayNotHasKey('recovery_time_objective', $data);
    }

    #[Test]
    public function toArrayIncludesAllSetFields(): void
    {
        $asset = new IctAsset(
            id: 'ICT-002',
            name: 'Cloud Database',
            type: 'database',
            owner: 'DBA Team',
            criticality: IctAssetCriticality::Critical,
            dependencies: ['ICT-001', 'ICT-003'],
            dataFlows: ['Customer data → Cloud DB → Reporting'],
            thirdPartyProvider: 'AWS',
            location: 'eu-west-1',
            recoveryTimeObjective: '4 hours',
            recoveryPointObjective: '1 hour',
        );

        /** @var array<string, mixed> $data */
        $data = $asset->toArray();

        /** @var list<string> $deps */
        $deps = $data['dependencies'];
        self::assertCount(2, $deps);
        /** @var list<string> $flows */
        $flows = $data['data_flows'];
        self::assertCount(1, $flows);
        self::assertSame('AWS', $data['third_party_provider']);
        self::assertSame('eu-west-1', $data['location']);
        self::assertSame('4 hours', $data['recovery_time_objective']);
        self::assertSame('1 hour', $data['recovery_point_objective']);
    }
}
