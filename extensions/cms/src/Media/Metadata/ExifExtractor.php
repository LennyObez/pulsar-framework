<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Metadata;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function exif_read_data;
use function in_array;
use function mime_content_type;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Extracts EXIF metadata from uploaded images.
 *
 * Supports JPEG and TIFF files via exif_read_data(). Other formats
 * return an empty MediaMetadata instance gracefully.
 */
#[Internal(reason: 'Use MediaMetadata DTO directly for public API')]
final readonly class ExifExtractor
{
    /** @var list<string> */
    private const array SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/tiff',
    ];

    /** @var list<string> */
    private const array SUPPORTED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'tiff',
        'tif',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Extract structured metadata from an image file.
     *
     * Returns an empty MediaMetadata for unsupported formats rather
     * than throwing, so callers can safely call this on any upload.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function extract(string $filePath): MediaMetadata
    {
        if (!$this->isSupported($filePath)) {
            return new MediaMetadata();
        }

        $rawExif = @exif_read_data($filePath, 'ANY_TAG', true);

        if ($rawExif === false) {
            $this->logger->debug('EXIF extraction returned no data', [
                'file' => $filePath,
            ]);

            return new MediaMetadata();
        }

        /** @var array<string, mixed> $rawExif */
        return MediaMetadata::fromExif($rawExif);
    }

    /**
     * Extract raw EXIF data without structuring it.
     *
     * @return array<string, mixed>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function extractRaw(string $filePath): array
    {
        if (!$this->isSupported($filePath)) {
            return [];
        }

        $rawExif = @exif_read_data($filePath, 'ANY_TAG', true);

        if ($rawExif === false) {
            return [];
        }

        /** @var array<string, mixed> $rawExif */
        return $rawExif;
    }

    /**
     * Whether the given file is an EXIF-capable format.
     */
    public function isSupported(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return true;
        }

        $mimeType = @mime_content_type($filePath);

        if ($mimeType === false) {
            return false;
        }

        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }
}
