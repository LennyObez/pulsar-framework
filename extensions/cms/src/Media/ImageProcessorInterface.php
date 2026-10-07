<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;

/**
 * Image processing operations for derivative generation.
 *
 * @psalm-api Public binding contract; implemented by ImageProcessor /
 *            ImagickImageProcessor and consumed by ImageVariantGenerator
 *            and MediaService.
 * @api
 */
#[Api(since: '1.0.0')]
interface ImageProcessorInterface
{
    /**
     * Resize an image to the given dimensions and format.
     *
     * @return string Path to the resized image
     */
    public function resize(string $sourcePath, int $width, ?int $height, string $format): string;

    /**
     * Generate a low-quality blur placeholder (LQIP) as a base64 data URI.
     */
    public function generateBlurPlaceholder(string $sourcePath): string;

    /**
     * Extract EXIF metadata from an image.
     *
     * @return array<string, mixed>
     */
    public function extractExif(string $sourcePath): array;

    /**
     * Strip EXIF metadata from an image.
     *
     * @return string Path to the stripped image
     */
    public function stripExif(string $sourcePath): string;
}
