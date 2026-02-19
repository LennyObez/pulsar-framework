<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * A downloadable file associated with a digital product.
 */
#[Api(since: '1.0.0')]
final readonly class DigitalAsset
{
    /**
     * @param string $id UUIDv7
     * @param string $productId UUIDv7 of the associated digital product
     * @param string $fileStoragePath Filesystem path to the downloadable file
     * @param string $fileHash SHA-256 hash of the file for integrity verification
     * @param string $fileName Original filename for download
     * @param int $fileSize File size in bytes
     * @param int $maxDownloads Maximum number of downloads allowed per purchase
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $fileStoragePath,
        public string $fileHash,
        public string $fileName,
        public int $fileSize,
        public int $maxDownloads,
    ) {}
}
