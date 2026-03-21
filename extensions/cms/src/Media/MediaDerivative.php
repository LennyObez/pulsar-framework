<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A derived variant of a media asset (e.g., thumbnail, WebP conversion).
 *
 * Derivatives are generated automatically from the original upload
 * and stored alongside the source asset.
 *
 * @psalm-api Public DTO returned from MediaRepositoryInterface; consumed by
 *            ResponsiveImageRenderer and admin views.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MediaDerivative
{
    /**
     * @param string $id UUIDv7
     * @param string $mediaAssetId UUIDv7 FK media_assets
     * @param string $variant Variant name (e.g., 'thumbnail', 'medium', 'large')
     * @param string $format Output format (e.g., 'webp', 'avif', 'jpeg')
     * @param string $storagePath Relative path within the storage disk
     * @param int $fileSize File size in bytes
     * @param int $width Width in pixels
     * @param int $height Height in pixels
     * @param string $fileHash SHA-256 hash of derivative contents
     * @param DateTimeImmutable $createdAt Creation timestamp
     */
    public function __construct(
        public string $id,
        public string $mediaAssetId,
        public string $variant,
        public string $format,
        public string $storagePath,
        public int $fileSize,
        public int $width,
        public int $height,
        public string $fileHash,
        public DateTimeImmutable $createdAt,
    ) {}
}
