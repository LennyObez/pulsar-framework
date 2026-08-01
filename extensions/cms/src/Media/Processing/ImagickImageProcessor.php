<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Processing;

use Imagick;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;

use function ceil;
use function exif_read_data;
use function extension_loaded;
use function is_string;
use function max;
use function sys_get_temp_dir;
use function tempnam;

use const PATHINFO_EXTENSION;

/**
 * Imagick-based image processor with ICC color profile preservation.
 *
 * Provides higher quality resampling (Lanczos) than GD, supports TIFF,
 * preserves ICC color profiles during resize, and converts CMYK to sRGB
 * for web display.
 */
#[Internal(reason: 'Use ImageProcessorInterface for public API')]
final readonly class ImagickImageProcessor implements ImageProcessorInterface
{
    public function __construct(
        private MediaConfig $config,
    ) {}

    /**
     * Whether the Imagick extension is available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    public function resize(string $sourcePath, int $width, ?int $height, string $format): string
    {
        $imagick = new Imagick($sourcePath);

        // Convert CMYK to sRGB for web display
        if ($imagick->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }

        $sourceWidth = $imagick->getImageWidth();
        $sourceHeight = $imagick->getImageHeight();

        // Calculate dimensions maintaining aspect ratio
        if ($height === null) {
            $ratio = (float) $sourceWidth / (float) $sourceHeight;
            $height = (int) ceil((float) $width / $ratio);
        }

        // Don't upscale
        if ($width > $sourceWidth) {
            $width = $sourceWidth;
            $height = $sourceHeight;
        }

        $width = max(1, $width);
        $height = max(1, $height);

        // Use Lanczos filter for highest quality resampling
        $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1.0);

        // Set output format and quality
        $this->configureOutput($imagick, $format);

        // Progressive JPEG
        if ($format === 'jpeg' || $format === 'jpg') {
            $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_img_');

        if ($tempFile === false) {
            $imagick->clear();

            throw CmsException::invalidImageFile();
        }

        $outputPath = $tempFile . '.' . $format;
        $imagick->writeImage($outputPath);
        $imagick->clear();

        return $outputPath;
    }

    public function generateBlurPlaceholder(string $sourcePath): string
    {
        $imagick = new Imagick($sourcePath);

        $sourceWidth = $imagick->getImageWidth();
        $sourceHeight = $imagick->getImageHeight();

        $targetWidth = 20;
        $targetHeight = max(1, (int) ceil(20.0 * (float) $sourceHeight / (float) $sourceWidth));

        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1.0);
        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality($this->config->webpQuality);

        $blob = $imagick->getImageBlob();
        $imagick->clear();

        return 'data:image/webp;base64,' . base64_encode($blob);
    }

    public function extractExif(string $sourcePath): array
    {
        $data = @exif_read_data($sourcePath, 'ANY_TAG', true);

        if ($data === false) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function stripExif(string $sourcePath): string
    {
        $imagick = new Imagick($sourcePath);

        // Preserve ICC profile before stripping
        $profiles = $imagick->getImageProfiles('icc', true);

        // Strip all metadata
        $imagick->stripImage();

        // Restore ICC profile
        if (isset($profiles['icc']) && is_string($profiles['icc'])) {
            $imagick->profileImage('icc', $profiles['icc']);
        }

        $format = $this->detectFormatFromFile($sourcePath);
        $this->configureOutput($imagick, $format);

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_exif_');

        if ($tempFile === false) {
            $imagick->clear();

            throw CmsException::invalidImageFile();
        }

        $outputPath = $tempFile . '.' . $format;
        $imagick->writeImage($outputPath);
        $imagick->clear();

        return $outputPath;
    }

    /**
     * Whether the source image has an embedded ICC color profile.
     */
    public function hasIccProfile(string $sourcePath): bool
    {
        $imagick = new Imagick($sourcePath);
        $profiles = $imagick->getImageProfiles('icc', true);
        $imagick->clear();

        return isset($profiles['icc']);
    }

    /**
     * Whether the source image is in CMYK color space.
     */
    public function isCmyk(string $sourcePath): bool
    {
        $imagick = new Imagick($sourcePath);
        $colorspace = $imagick->getImageColorspace();
        $imagick->clear();

        return $colorspace === Imagick::COLORSPACE_CMYK;
    }

    private function configureOutput(Imagick $imagick, string $format): void
    {
        match ($format) {
            'jpeg', 'jpg' => $this->configureJpeg($imagick),
            'png' => $imagick->setImageFormat('png'),
            'webp' => $this->configureWebp($imagick),
            'avif' => $this->configureAvif($imagick),
            'tiff', 'tif' => $imagick->setImageFormat('tiff'),
            'gif' => $imagick->setImageFormat('gif'),
            default => $this->configureJpeg($imagick),
        };
    }

    private function configureJpeg(Imagick $imagick): void
    {
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality($this->config->jpegQuality);
    }

    private function configureWebp(Imagick $imagick): void
    {
        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality($this->config->webpQuality);
    }

    private function configureAvif(Imagick $imagick): void
    {
        $imagick->setImageFormat('avif');
        $imagick->setImageCompressionQuality($this->config->avifQuality);
    }

    private function detectFormatFromFile(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            'avif' => 'avif',
            'tiff', 'tif' => 'tiff',
            default => 'jpeg',
        };
    }
}
