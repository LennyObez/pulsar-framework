<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function array_map;
use function is_array;

/**
 * Media upload and processing configuration.
 */
#[Api(since: '1.0.0')]
final readonly class MediaConfig
{
    /**
     * @param string $disk Storage disk name
     * @param int $maxUploadSize Maximum upload size in bytes (default: 10 MB)
     * @param list<string> $allowedMimeTypes Permitted MIME types for upload
     * @param list<string> $allowedExtensions Permitted file extensions
     * @param int $maxImageWidth Maximum image width in pixels
     * @param int $maxImageHeight Maximum image height in pixels
     * @param int $maxPixelCount Maximum total pixel count (width x height)
     * @param bool $preserveExif Whether to preserve EXIF data on images
     * @param int $webpQuality WebP conversion quality (0-100)
     * @param int $avifQuality AVIF conversion quality (0-100)
     * @param bool $avifEnabled Whether AVIF derivative generation is enabled
     * @param string $storagePath Base storage path for media files
     * @param list<ImageVariantConfig> $imageVariants Configured image variant definitions
     */
    public function __construct(
        public string $disk = 'local',
        public int $maxUploadSize = 10_485_760,
        public array $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
            'image/gif',
            'image/svg+xml',
            'application/pdf',
        ],
        public array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'pdf'],
        public int $maxImageWidth = 16384,
        public int $maxImageHeight = 16384,
        public int $maxPixelCount = 100_000_000,
        public bool $preserveExif = false,
        public int $webpQuality = 80,
        public int $avifQuality = 60,
        public bool $avifEnabled = true,
        public string $storagePath = 'storage/cms/media',
        public array $imageVariants = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawVariants = $data['image_variants'] ?? [];
        $imageVariants = is_array($rawVariants)
            ? array_map(
                /** @param array<string, mixed> $v */
                static fn(array $v): ImageVariantConfig => ImageVariantConfig::fromArray($v),
                $rawVariants,
            )
            : [];

        return new self(
            disk: (string) ($data['disk'] ?? 'local'),
            maxUploadSize: (int) ($data['max_upload_size'] ?? 10_485_760),
            allowedMimeTypes: (array) ($data['allowed_mime_types'] ?? [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/avif',
                'image/gif',
                'image/svg+xml',
                'application/pdf',
            ]),
            allowedExtensions: (array) ($data['allowed_extensions'] ?? [
                'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'pdf',
            ]),
            maxImageWidth: (int) ($data['max_image_width'] ?? 16384),
            maxImageHeight: (int) ($data['max_image_height'] ?? 16384),
            maxPixelCount: (int) ($data['max_pixel_count'] ?? 100_000_000),
            preserveExif: (bool) ($data['preserve_exif'] ?? false),
            webpQuality: (int) ($data['webp_quality'] ?? 80),
            avifQuality: (int) ($data['avif_quality'] ?? 60),
            avifEnabled: (bool) ($data['avif_enabled'] ?? true),
            storagePath: (string) ($data['storage_path'] ?? 'storage/cms/media'),
            imageVariants: $imageVariants,
        );
    }
}
