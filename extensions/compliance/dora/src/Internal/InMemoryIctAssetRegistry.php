<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Dora\Risk\IctAsset;
use Pulsar\Extension\Dora\Risk\IctAssetCriticality;
use Pulsar\Extension\Dora\Risk\IctAssetRegistryInterface;

use function in_array;

/**
 * In-memory ICT asset registry for development and testing.
 */
#[Internal(reason: 'Reference implementation; use IctAssetRegistryInterface for public API')]
final class InMemoryIctAssetRegistry implements IctAssetRegistryInterface
{
    /** @var array<string, IctAsset> */
    private array $assets = [];

    #[Override]
    public function register(IctAsset $asset): void
    {
        $this->assets[$asset->id] = $asset;
    }

    #[Override]
    public function find(string $id): ?IctAsset
    {
        return $this->assets[$id] ?? null;
    }

    #[Override]
    public function listAssets(?IctAssetCriticality $criticality = null): array
    {
        $results = [];

        foreach ($this->assets as $asset) {
            if ($criticality === null || $asset->criticality === $criticality) {
                $results[] = $asset;
            }
        }

        return $results;
    }

    #[Override]
    public function findDependents(string $assetId): array
    {
        $results = [];

        foreach ($this->assets as $asset) {
            if (in_array($assetId, $asset->dependencies, true)) {
                $results[] = $asset;
            }
        }

        return $results;
    }

    #[Override]
    public function findByProvider(string $providerName): array
    {
        $results = [];

        foreach ($this->assets as $asset) {
            if ($asset->thirdPartyProvider === $providerName) {
                $results[] = $asset;
            }
        }

        return $results;
    }
}
