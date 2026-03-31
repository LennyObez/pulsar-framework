<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

/**
 * Represents a generated image variant with its physical dimensions,
 * output format, and file size.
 *
 * @psalm-api Public DTO returned from ImageVariantGenerator; consumed by
 *            MediaDerivative records and admin views.
 * @api
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
     * @param array{
     *     path?: string,
     *     width?: int,
     *     height?: int,
     *     format?: string,
     *     size_bytes?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'] ?? '',
            width: $data['width'] ?? 0,
            height: $data['height'] ?? 0,
            format: $data['format'] ?? '',
            sizeBytes: $data['size_bytes'] ?? 0,
        );
    }
}
