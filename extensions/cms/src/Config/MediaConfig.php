<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawVariants = is_array($data['image_variants'] ?? null) ? $data['image_variants'] : [];
        /** @var list<ImageVariantConfig> $imageVariants */
        $imageVariants = [];

        foreach ($rawVariants as $v) {
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $imageVariants[] = ImageVariantConfig::fromArray($v);
            }
        }

        $rawDisk = $data['disk'] ?? null;
        $rawMaxUploadSize = $data['max_upload_size'] ?? null;
        $rawMaxImageWidth = $data['max_image_width'] ?? null;
        $rawMaxImageHeight = $data['max_image_height'] ?? null;
        $rawMaxPixelCount = $data['max_pixel_count'] ?? null;
        $rawPreserveExif = $data['preserve_exif'] ?? null;
        $rawJpegQuality = $data['jpeg_quality'] ?? null;
        $rawWebpQuality = $data['webp_quality'] ?? null;
        $rawAvifQuality = $data['avif_quality'] ?? null;
        $rawAvifEnabled = $data['avif_enabled'] ?? null;
        $rawStoragePath = $data['storage_path'] ?? null;
        $rawProgressiveJpeg = $data['progressive_jpeg'] ?? null;
        $rawPreserveOriginal = $data['preserve_original'] ?? null;

        return new self(
            disk: is_string($rawDisk) ? $rawDisk : 'local',
            maxUploadSize: is_int($rawMaxUploadSize) ? $rawMaxUploadSize : 52_428_800,
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
            maxImageWidth: is_int($rawMaxImageWidth) ? $rawMaxImageWidth : 16384,
            maxImageHeight: is_int($rawMaxImageHeight) ? $rawMaxImageHeight : 16384,
            maxPixelCount: is_int($rawMaxPixelCount) ? $rawMaxPixelCount : 100_000_000,
            preserveExif: is_bool($rawPreserveExif) ? $rawPreserveExif : false,
            jpegQuality: is_int($rawJpegQuality) ? $rawJpegQuality : 85,
            webpQuality: is_int($rawWebpQuality) ? $rawWebpQuality : 80,
            avifQuality: is_int($rawAvifQuality) ? $rawAvifQuality : 60,
            avifEnabled: is_bool($rawAvifEnabled) ? $rawAvifEnabled : true,
            storagePath: is_string($rawStoragePath) ? $rawStoragePath : 'storage/cms/media',
            imageVariants: $imageVariants,
            progressiveJpeg: is_bool($rawProgressiveJpeg) ? $rawProgressiveJpeg : true,
            preserveOriginal: is_bool($rawPreserveOriginal) ? $rawPreserveOriginal : true,
        );
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private static function toStringList(mixed $raw, array $default): array
    {
        if (!is_array($raw)) {
            return $default;
        }
        $result = [];
        foreach ($raw as $item) {
            $result[] = is_string($item) ? $item : '';
        }

        return $result;
    }
}
