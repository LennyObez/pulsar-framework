<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for digital asset and download entitlement management.
 */
#[Api(since: '1.0.0')]
interface DigitalAssetRepositoryInterface
{
    /**
     * Find all digital assets associated with a product.
     *
     * @return list<DigitalAsset>
     */
    public function findByProduct(string $productId): array;

    /**
     * Resolve a download entitlement by its secure token.
     */
    public function findByToken(string $token): ?DigitalDownload;

    public function save(DigitalAsset $digitalAsset): void;

    public function saveDownload(DigitalDownload $download): void;

    /**
     * Atomically decrement the remaining download count.
     *
     * @return int Number of rows affected (0 if already at zero)
     */
    public function decrementDownloads(string $downloadId): int;
}
