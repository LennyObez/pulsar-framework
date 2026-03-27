<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Configuration for a single image variant (responsive size).
 *
 * @psalm-api Public configuration DTO referenced by MediaConfig; consumed
 *            by image variant generation jobs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ImageVariantConfig
{
    /**
     * @param string $name Variant identifier (e.g., 'thumbnail', 'medium', 'large')
     * @param int $maxWidth Maximum width in pixels
     * @param int $maxHeight Maximum height in pixels
     * @param string $format Output format: 'original', 'webp', or 'avif'
     * @param int $quality Compression quality (1-100)
     */
    public function __construct(
        public string $name,
        public int $maxWidth,
        public int $maxHeight,
        public string $format = 'original',
        public int $quality = 80,
    ) {}

    /**
     * @param array{
     *     name?: string,
     *     max_width?: int,
     *     max_height?: int,
     *     format?: string,
     *     quality?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            maxWidth: $data['max_width'] ?? 0,
            maxHeight: $data['max_height'] ?? 0,
            format: $data['format'] ?? 'original',
            quality: $data['quality'] ?? 80,
        );
    }
}
