<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

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
            path: (string) ($data['path'] ?? ''),
            width: (int) ($data['width'] ?? 0),
            height: (int) ($data['height'] ?? 0),
            format: (string) ($data['format'] ?? ''),
            sizeBytes: (int) ($data['size_bytes'] ?? 0),
        );
    }
}
