<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Media upload and processing configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by media upload controllers and processing jobs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MediaConfig
{
    /**
     * @param string $disk Storage disk name
     * @param int $maxUploadSize Maximum upload size in bytes (default: 50 MB)
     * @param list<string> $allowedMimeTypes Permitted MIME types for upload
     * @param list<string> $allowedExtensions Permitted file extensions
     * @param int $maxImageWidth Maximum image width in pixels
     * @param int $maxImageHeight Maximum image height in pixels
     * @param int $maxPixelCount Maximum total pixel count (width x height)
     * @param bool $preserveExif Whether to preserve EXIF data on images
     * @param int $jpegQuality JPEG compression quality (0-100)
     * @param int $webpQuality WebP conversion quality (0-100)
     * @param int $avifQuality AVIF conversion quality (0-100)
     * @param bool $avifEnabled Whether AVIF derivative generation is enabled
     * @param string $storagePath Base storage path for media files
     * @param list<ImageVariantConfig> $imageVariants Configured image variant definitions
     * @param bool $progressiveJpeg Whether to generate progressive JPEGs
     * @param bool $preserveOriginal Whether to always store untouched uploads alongside derivatives
     */
    public function __construct(
        public string $disk = 'local',
        public int $maxUploadSize = 52_428_800,
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
        public int $jpegQuality = 85,
        public int $webpQuality = 80,
        public int $avifQuality = 60,
        public bool $avifEnabled = true,
        public string $storagePath = 'storage/cms/media',
        public array $imageVariants = [],
        public bool $progressiveJpeg = true,
        public bool $preserveOriginal = true,
    ) {}

    /**
     * @param array{
     *     disk?: string,
     *     max_upload_size?: int,
     *     allowed_mime_types?: list<string>,
     *     allowed_extensions?: list<string>,
     *     max_image_width?: int,
     *     max_image_height?: int,
     *     max_pixel_count?: int,
     *     preserve_exif?: bool,
     *     jpeg_quality?: int,
     *     webp_quality?: int,
     *     avif_quality?: int,
     *     avif_enabled?: bool,
     *     storage_path?: string,
     *     image_variants?: list<array<string, mixed>>,
     *     progressive_jpeg?: bool,
     *     preserve_original?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $rawVariants = $data['image_variants'] ?? [];
        $imageVariants = [];

        foreach ($rawVariants as $v) {
            $imageVariants[] = ImageVariantConfig::fromArray($v);
        }

        return new self(
            disk: $data['disk'] ?? 'local',
            maxUploadSize: $data['max_upload_size'] ?? 52_428_800,
            allowedMimeTypes: self::toStringList($data['allowed_mime_types'] ?? null, [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/avif',
                'image/gif',
                'image/svg+xml',
                'application/pdf',
            ]),
            allowedExtensions: self::toStringList($data['allowed_extensions'] ?? null, [
                'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'pdf',
            ]),
            maxImageWidth: $data['max_image_width'] ?? 16384,
            maxImageHeight: $data['max_image_height'] ?? 16384,
            maxPixelCount: $data['max_pixel_count'] ?? 100_000_000,
            preserveExif: $data['preserve_exif'] ?? false,
            jpegQuality: $data['jpeg_quality'] ?? 85,
            webpQuality: $data['webp_quality'] ?? 80,
            avifQuality: $data['avif_quality'] ?? 60,
            avifEnabled: $data['avif_enabled'] ?? true,
            storagePath: $data['storage_path'] ?? 'storage/cms/media',
            imageVariants: $imageVariants,
            progressiveJpeg: $data['progressive_jpeg'] ?? true,
            preserveOriginal: $data['preserve_original'] ?? true,
        );
    }

    /**
     * @param list<string>|null $raw
     * @param list<string>      $default
     * @return list<string>
     */
    private static function toStringList(?array $raw, array $default): array
    {
        return $raw ?? $default;
    }
}
