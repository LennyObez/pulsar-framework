<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

use function is_string;

/**
 * Represents a generated image variant with its physical dimensions,
 * output format, and file size.
 */
#[Api(since: '1.0.0')]
final readonly class ImageVariant
{
    public function __construct(
        public string $path,
        public int $width,
        public int $height,
        public string $format,
        public int $sizeBytes,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: isset($data['path']) ? (is_string($data['path'] ?? null) ? $data['path'] : '') : '',
            width: is_numeric($data['width'] ?? null) ? (int) $data['width'] : 0,
            height: is_numeric($data['height'] ?? null) ? (int) $data['height'] : 0,
            format: is_string($data['format'] ?? null) ? $data['format'] : '',
            sizeBytes: is_numeric($data['size_bytes'] ?? null) ? (int) $data['size_bytes'] : 0,
        );
    }
}
