<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_int;
use function is_string;

/**
 * Configuration for a single image variant (responsive size).
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
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            maxWidth: is_int($data['max_width'] ?? null) ? $data['max_width'] : 0,
            maxHeight: is_int($data['max_height'] ?? null) ? $data['max_height'] : 0,
            format: is_string($data['format'] ?? null) ? $data['format'] : 'original',
            quality: is_int($data['quality'] ?? null) ? $data['quality'] : 80,
        );
    }
}
