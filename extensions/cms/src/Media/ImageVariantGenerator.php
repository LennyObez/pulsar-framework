<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Config\ImageVariantConfig;

use function array_map;
use function file_exists;
use function file_get_contents;
use function getimagesize;
use function min;
use function pathinfo;
use function round;
use function sprintf;
use function strlen;
use function strtolower;
use function unlink;

use const PATHINFO_EXTENSION;

/**
 * Generates image variants (responsive sizes with optional format conversion)
 * from an original image using the configured variant definitions.
 */
#[Api(since: '1.0.0')]
final readonly class ImageVariantGenerator
{
    /** @var list<array{name: string, max_width: int, max_height: int, format: string, quality: int}> */
    private const array DEFAULT_VARIANTS = [
        ['name' => 'thumbnail', 'max_width' => 150, 'max_height' => 150, 'format' => 'original', 'quality' => 80],
        ['name' => 'medium', 'max_width' => 600, 'max_height' => 600, 'format' => 'original', 'quality' => 80],
        ['name' => 'large', 'max_width' => 1200, 'max_height' => 1200, 'format' => 'original', 'quality' => 80],
    ];

    public function __construct(
        private ImageProcessorInterface $imageProcessor,
        private MediaDiskInterface $disk,
    ) {}

    /**
     * Generate image variants for the given original file.
     *
     * @param string $originalPath Path to the original image on disk (absolute temp path for processing)
     * @param string $storagePath Relative storage path of the original in the media disk
     * @param list<ImageVariantConfig> $variantConfigs Variant definitions (uses defaults if empty)
     *
     * @return list<ImageVariant>
     */
    public function generate(string $originalPath, string $storagePath, array $variantConfigs = []): array
    {
        if ($variantConfigs === []) {
            $variantConfigs = array_map(
                static fn(array $data): ImageVariantConfig => ImageVariantConfig::fromArray($data),
                self::DEFAULT_VARIANTS,
            );
        }

        $originalInfo = @getimagesize($originalPath);

        if ($originalInfo === false) {
            return [];
        }

        $originalWidth = $originalInfo[0];
        $originalHeight = $originalInfo[1];
        $originalFormat = $this->detectFormat($originalPath);

        $variants = [];

        foreach ($variantConfigs as $config) {
            // Skip if original is smaller than target dimensions
            if ($originalWidth <= $config->maxWidth && $originalHeight <= $config->maxHeight) {
                continue;
            }

            // Determine output format
            $outputFormat = $config->format === 'original' ? $originalFormat : $config->format;

            // Calculate dimensions preserving aspect ratio within max bounds
            [$targetWidth, $targetHeight] = $this->calculateDimensions(
                $originalWidth,
                $originalHeight,
                $config->maxWidth,
                $config->maxHeight,
            );

            // Resize via image processor (returns temp file path)
            $resizedPath = $this->imageProcessor->resize($originalPath, $targetWidth, $targetHeight, $outputFormat);

            try {
                $resizedContents = file_get_contents($resizedPath);

                if ($resizedContents === false) {
                    continue;
                }

                // Determine actual output dimensions
                $resizedInfo = @getimagesize($resizedPath);
                $actualWidth = $resizedInfo !== false ? $resizedInfo[0] : $targetWidth;
                $actualHeight = $resizedInfo !== false ? $resizedInfo[1] : $targetHeight;

                // Build variant storage path
                $variantStoragePath = $this->buildVariantPath($storagePath, $config->name, $outputFormat);

                // Write to disk
                $this->disk->write($variantStoragePath, $resizedContents);

                $variants[] = new ImageVariant(
                    path: $variantStoragePath,
                    width: $actualWidth,
                    height: $actualHeight,
                    format: $outputFormat,
                    sizeBytes: strlen($resizedContents),
                );
            } finally {
                if (file_exists($resizedPath)) {
                    unlink($resizedPath);
                }
            }
        }

        return $variants;
    }

    /**
     * Calculate target dimensions that fit within max bounds while preserving aspect ratio.
     *
     * @return array{0: int, 1: int}
     */
    private function calculateDimensions(
        int $sourceWidth,
        int $sourceHeight,
        int $maxWidth,
        int $maxHeight,
    ): array {
        $ratioW = (float) $maxWidth / (float) $sourceWidth;
        $ratioH = (float) $maxHeight / (float) $sourceHeight;
        $ratio = min($ratioW, $ratioH);

        return [
            (int) round((float) $sourceWidth * $ratio),
            (int) round((float) $sourceHeight * $ratio),
        ];
    }

    /**
     * Build the storage path for a variant alongside the original.
     */
    private function buildVariantPath(string $originalStoragePath, string $variantName, string $format): string
    {
        $pathInfo = pathinfo($originalStoragePath);
        $dir = $pathInfo['dirname'] ?? '';
        $name = $pathInfo['filename'] ?? 'unknown';

        return sprintf('%s/%s-%s.%s', $dir, $name, $variantName, $format);
    }

    /**
     * Detect the image format from a file path's extension.
     */
    private function detectFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'avif' => 'avif',
            default => 'jpeg',
        };
    }
}
