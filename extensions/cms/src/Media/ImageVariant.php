<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: Coerce::string($data['path'] ?? null),
            width: Coerce::int($data['width'] ?? null, 0),
            height: Coerce::int($data['height'] ?? null, 0),
            format: Coerce::string($data['format'] ?? null),
            sizeBytes: Coerce::int($data['size_bytes'] ?? null, 0),
        );
    }
}
