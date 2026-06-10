<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::string($data['name'] ?? null),
            maxWidth: Coerce::int($data['max_width'] ?? null, 0),
            maxHeight: Coerce::int($data['max_height'] ?? null, 0),
            format: Coerce::string($data['format'] ?? null, 'original'),
            quality: Coerce::int($data['quality'] ?? null, 80),
        );
    }
}
