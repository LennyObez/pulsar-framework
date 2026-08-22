<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Dora\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dora\Internal\InMemoryIctAssetRegistry;
use Pulsar\Extension\Dora\Risk\IctAsset;
use Pulsar\Extension\Dora\Risk\IctAssetCriticality;

#[CoversClass(InMemoryIctAssetRegistry::class)]
final class InMemoryIctAssetRegistryTest extends TestCase
{
    private InMemoryIctAssetRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryIctAssetRegistry();
    }

    /** @param list<string> $deps */
    private function createAsset(string $id, IctAssetCriticality $criticality = IctAssetCriticality::Standard, array $deps = [], ?string $provider = null): IctAsset
    {
        return new IctAsset(
            id: $id,
            name: "Asset $id",
            type: 'application',
            owner: 'IT',
            criticality: $criticality,
            dependencies: $deps,
            thirdPartyProvider: $provider,
        );
    }

    #[Test]
    public function registersAndFindsById(): void
    {
        $asset = $this->createAsset('ICT-001');
        $this->registry->register($asset);

        self::assertSame($asset, $this->registry->find('ICT-001'));
    }

    #[Test]
    public function returnsNullForUnknown(): void
    {
        self::assertNull($this->registry->find('UNKNOWN'));
    }

    #[Test]
    public function listsAllAssets(): void
    {
        $this->registry->register($this->createAsset('ICT-001'));
        $this->registry->register($this->createAsset('ICT-002'));

        self::assertCount(2, $this->registry->listAssets());
    }

    #[Test]
    public function filtersAssetsByCriticality(): void
    {
        $this->registry->register($this->createAsset('ICT-001', IctAssetCriticality::Critical));
        $this->registry->register($this->createAsset('ICT-002', IctAssetCriticality::Low));
        $this->registry->register($this->createAsset('ICT-003', IctAssetCriticality::Critical));

        $critical = $this->registry->listAssets(IctAssetCriticality::Critical);

        self::assertCount(2, $critical);
    }

    #[Test]
    public function findsDependentAssets(): void
    {
        $this->registry->register($this->createAsset('ICT-001'));
        $this->registry->register($this->createAsset('ICT-002', deps: ['ICT-001']));
        $this->registry->register($this->createAsset('ICT-003', deps: ['ICT-001']));
        $this->registry->register($this->createAsset('ICT-004', deps: ['ICT-002']));

        $dependents = $this->registry->findDependents('ICT-001');

        self::assertCount(2, $dependents);
    }

    #[Test]
    public function findsByProvider(): void
    {
        $this->registry->register($this->createAsset('ICT-001', provider: 'AWS'));
        $this->registry->register($this->createAsset('ICT-002', provider: 'Azure'));
        $this->registry->register($this->createAsset('ICT-003', provider: 'AWS'));

        $awsAssets = $this->registry->findByProvider('AWS');

        self::assertCount(2, $awsAssets);
    }

    #[Test]
    public function findByProviderReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->registry->findByProvider('GCP'));
    }

    #[Test]
    public function overwritesExistingAsset(): void
    {
        $this->registry->register($this->createAsset('ICT-001', IctAssetCriticality::Low));
        $this->registry->register($this->createAsset('ICT-001', IctAssetCriticality::Critical));

        $found = $this->registry->find('ICT-001');
        self::assertSame(IctAssetCriticality::Critical, $found?->criticality);
        self::assertCount(1, $this->registry->listAssets());
    }
}
