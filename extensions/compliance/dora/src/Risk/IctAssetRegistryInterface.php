<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Risk;

use Pulsar\Api\Api;

/**
 * Contract for the ICT asset register per DORA Article 8.
 *
 * Financial entities shall identify, classify, and document all ICT assets
 * including those managed by third-party providers.
 * @api
 */
#[Api(since: '1.0.0')]
interface IctAssetRegistryInterface
{
    /**
     * Register an ICT asset.
     */
    public function register(IctAsset $asset): void;

    /**
     * Retrieve an asset by ID.
     */
    public function find(string $id): ?IctAsset;

    /**
     * List all assets, optionally filtered by criticality.
     *
     * @return list<IctAsset>
     */
    public function listAssets(?IctAssetCriticality $criticality = null): array;

    /**
     * Find all assets that depend on the given asset.
     *
     * @return list<IctAsset>
     */
    public function findDependents(string $assetId): array;

    /**
     * Find all assets provided by a specific third-party provider.
     *
     * @return list<IctAsset>
     */
    public function findByProvider(string $providerName): array;
}
