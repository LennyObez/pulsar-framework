<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for the MediaAsset aggregate root.
 */
#[Api(since: '1.0.0')]
interface MediaRepositoryInterface
{
    public function findById(string $id): ?MediaAsset;

    public function findByHash(string $hash): ?MediaAsset;

    /**
     * @return PaginationResult<MediaAsset>
     */
    public function listAssets(
        ?string $tenantId,
        int $page,
        int $perPage,
        ?string $mimeType = null,
        ?string $visibility = null,
    ): PaginationResult;

    public function save(MediaAsset $asset): void;

    public function delete(MediaAsset $asset): void;

    /**
     * @return list<MediaDerivative>
     */
    public function findDerivatives(string $assetId): array;

    public function saveDerivative(MediaDerivative $derivative): void;

    public function saveTranslation(MediaAssetTranslation $translation): void;

    /**
     * @return list<MediaAssetTranslation>
     */
    public function findTranslations(string $assetId): array;
}
