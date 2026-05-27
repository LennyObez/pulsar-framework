<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

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
        $rawVariants = $data['image_variants'] ?? null;
        $imageVariants = [];

        if (is_array($rawVariants)) {
            foreach ($rawVariants as $v) {
                if (is_array($v)) {
                    $imageVariants[] = ImageVariantConfig::fromArray($v);
                }
            }
        }

        return new self(
            disk: Coerce::string($data['disk'] ?? null, 'local'),
            maxUploadSize: Coerce::int($data['max_upload_size'] ?? null, 52_428_800),
            allowedMimeTypes: Coerce::listOfString($data['allowed_mime_types'] ?? null, [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/avif',
                'image/gif',
                'image/svg+xml',
                'application/pdf',
            ]),
            allowedExtensions: Coerce::listOfString($data['allowed_extensions'] ?? null, [
                'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'pdf',
            ]),
            maxImageWidth: Coerce::int($data['max_image_width'] ?? null, 16384),
            maxImageHeight: Coerce::int($data['max_image_height'] ?? null, 16384),
            maxPixelCount: Coerce::int($data['max_pixel_count'] ?? null, 100_000_000),
            preserveExif: Coerce::strictBool($data['preserve_exif'] ?? null),
            jpegQuality: Coerce::int($data['jpeg_quality'] ?? null, 85),
            webpQuality: Coerce::int($data['webp_quality'] ?? null, 80),
            avifQuality: Coerce::int($data['avif_quality'] ?? null, 60),
            avifEnabled: Coerce::strictBool($data['avif_enabled'] ?? null, true),
            storagePath: Coerce::string($data['storage_path'] ?? null, 'storage/cms/media'),
            imageVariants: $imageVariants,
            progressiveJpeg: Coerce::strictBool($data['progressive_jpeg'] ?? null, true),
            preserveOriginal: Coerce::strictBool($data['preserve_original'] ?? null, true),
        );
    }
}
