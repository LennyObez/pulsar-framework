<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Tests\Unit\Internal;

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

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->registry->find('nonexistent'));
    }

    #[Test]
    public function registerAndFindRetrievesAsset(): void
    {
        $asset = $this->createAsset('A1', 'Service A', IctAssetCriticality::Critical);
        $this->registry->register($asset);

        $found = $this->registry->find('A1');
        self::assertNotNull($found);
        self::assertSame('Service A', $found->name);
    }

    #[Test]
    public function registerOverwritesSameId(): void
    {
        $asset1 = $this->createAsset('A1', 'Version 1', IctAssetCriticality::Standard);
        $asset2 = $this->createAsset('A1', 'Version 2', IctAssetCriticality::Critical);

        $this->registry->register($asset1);
        $this->registry->register($asset2);

        $found = $this->registry->find('A1');
        self::assertNotNull($found);
        self::assertSame('Version 2', $found->name);
    }

    #[Test]
    public function listAssetsReturnsAllWhenNoCriticalityFilter(): void
    {
        $this->registry->register($this->createAsset('A1', 'S1', IctAssetCriticality::Critical));
        $this->registry->register($this->createAsset('A2', 'S2', IctAssetCriticality::Standard));
        $this->registry->register($this->createAsset('A3', 'S3', IctAssetCriticality::Low));

        $all = $this->registry->listAssets();
        self::assertCount(3, $all);
    }

    #[Test]
    public function listAssetsFiltersByCriticality(): void
    {
        $this->registry->register($this->createAsset('A1', 'Critical1', IctAssetCriticality::Critical));
        $this->registry->register($this->createAsset('A2', 'Standard1', IctAssetCriticality::Standard));
        $this->registry->register($this->createAsset('A3', 'Critical2', IctAssetCriticality::Critical));

        $critical = $this->registry->listAssets(IctAssetCriticality::Critical);
        self::assertCount(2, $critical);

        $standard = $this->registry->listAssets(IctAssetCriticality::Standard);
        self::assertCount(1, $standard);

        $low = $this->registry->listAssets(IctAssetCriticality::Low);
        self::assertCount(0, $low);
    }

    #[Test]
    public function findDependentsReturnsDependentAssets(): void
    {
        $this->registry->register($this->createAsset('DB', 'Database', IctAssetCriticality::Critical));
        $this->registry->register(new IctAsset(
            id: 'API',
            name: 'API Service',
            type: 'service',
            owner: 'Dev',
            criticality: IctAssetCriticality::Important,
            dependencies: ['DB'],
        ));
        $this->registry->register(new IctAsset(
            id: 'WEB',
            name: 'Web Frontend',
            type: 'application',
            owner: 'Dev',
            criticality: IctAssetCriticality::Standard,
            dependencies: ['API'],
        ));

        $dbDependents = $this->registry->findDependents('DB');
        self::assertCount(1, $dbDependents);
        self::assertSame('API', $dbDependents[0]->id);

        $apiDependents = $this->registry->findDependents('API');
        self::assertCount(1, $apiDependents);
        self::assertSame('WEB', $apiDependents[0]->id);

        $webDependents = $this->registry->findDependents('WEB');
        self::assertCount(0, $webDependents);
    }

    #[Test]
    public function findByProviderReturnsMatchingAssets(): void
    {
        $this->registry->register(new IctAsset(
            id: 'A1',
            name: 'Cloud DB',
            type: 'database',
            owner: 'DBA',
            criticality: IctAssetCriticality::Critical,
            thirdPartyProvider: 'AWS',
        ));
        $this->registry->register(new IctAsset(
            id: 'A2',
            name: 'CDN',
            type: 'infrastructure',
            owner: 'Infra',
            criticality: IctAssetCriticality::Standard,
            thirdPartyProvider: 'Cloudflare',
        ));
        $this->registry->register(new IctAsset(
            id: 'A3',
            name: 'Storage',
            type: 'storage',
            owner: 'Infra',
            criticality: IctAssetCriticality::Important,
            thirdPartyProvider: 'AWS',
        ));

        $aws = $this->registry->findByProvider('AWS');
        self::assertCount(2, $aws);

        $cf = $this->registry->findByProvider('Cloudflare');
        self::assertCount(1, $cf);

        $unknown = $this->registry->findByProvider('Unknown');
        self::assertCount(0, $unknown);
    }

    #[Test]
    public function listAssetsReturnsEmptyForEmptyRegistry(): void
    {
        self::assertSame([], $this->registry->listAssets());
    }

    private function createAsset(string $id, string $name, IctAssetCriticality $criticality): IctAsset
    {
        return new IctAsset(
            id: $id,
            name: $name,
            type: 'service',
            owner: 'IT',
            criticality: $criticality,
        );
    }
}
