<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media;

use GdImage;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\MediaConfig;
use Pulsar\Extension\Cms\Exception\CmsException;

use function ceil;
use function exif_read_data;
use function imageavif;
use function imagecolorallocatealpha;
use function imagecopyresampled;
use function imagecreatefromgif;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagecreatefromwebp;
use function imagecreatetruecolor;
use function imagefill;
use function imagegif;
use function imageinterlace;
use function imagejpeg;
use function imagepng;
use function imagesx;
use function imagesy;
use function imagewebp;

/**
 * GD-based image processor for derivative generation.
 *
 * Supports resize, format conversion (WebP, AVIF), EXIF extraction/stripping,
 * and blur placeholder generation.
 */
#[Internal(reason: 'Use ImageProcessorInterface for public API')]
final readonly class ImageProcessor implements ImageProcessorInterface
{
    public function __construct(
        private MediaConfig $config,
    ) {}

    public function resize(string $sourcePath, int $width, ?int $height, string $format): string
    {
        $source = $this->loadImage($sourcePath);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

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

        $destination = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($destination === false) {
            throw CmsException::invalidImageFile();
        }

        // Preserve alpha transparency for PNG and WebP
        if ($format === 'png' || $format === 'webp') {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);

            if ($transparent !== false) {
                imagefill($destination, 0, 0, $transparent);
            }
        }

        imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_img_');

        if ($tempFile === false) {
            throw CmsException::invalidImageFile();
        }

        $outputPath = $tempFile . '.' . $format;
        $this->saveImage($destination, $outputPath, $format);

        unset($source, $destination);

        return $outputPath;
    }

    public function generateBlurPlaceholder(string $sourcePath): string
    {
        $source = $this->loadImage($sourcePath);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        // Resize to 20px wide, proportional height
        $targetWidth = 20;
        $targetHeight = max(1, (int) ceil(20.0 * (float) $sourceHeight / (float) $sourceWidth));

        $destination = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($destination === false) {
            throw CmsException::invalidImageFile();
        }

        imagecopyresampled($destination, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        // Capture WebP output to buffer
        ob_start();
        imagewebp($destination, null, $this->config->webpQuality);
        $buffer = ob_get_clean();

        unset($source, $destination);

        if ($buffer === false || $buffer === '') {
            throw CmsException::invalidImageFile();
        }

        return 'data:image/webp;base64,' . base64_encode($buffer);
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
        // GD re-encode inherently strips EXIF data: detect source format
        // and re-encode in the same format to avoid lossy conversion.
        $info = @getimagesize($sourcePath);
        $format = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };

        $source = $this->loadImage($sourcePath);
        $tempFile = tempnam(sys_get_temp_dir(), 'pulsar_exif_');

        if ($tempFile === false) {
            throw CmsException::invalidImageFile();
        }

        $outputPath = $tempFile . '.' . $format;
        $this->saveImage($source, $outputPath, $format);
        unset($source);

        return $outputPath;
    }

    /**
     * Load an image from file using GD.
     *
     * @throws CmsException If the image cannot be loaded
     */
    private function loadImage(string $path): GdImage
    {
        $info = @getimagesize($path);

        if ($info === false) {
            throw CmsException::invalidImageFile();
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            throw CmsException::invalidImageFile();
        }

        return $image;
    }

    /**
     * Save a GD image to a file in the specified format.
     *
     * @throws CmsException If the format is unsupported
     */
    private function saveImage(GdImage $image, string $path, string $format): void
    {
        // Enable progressive JPEG for better perceived loading on slow connections
        if (($format === 'jpeg' || $format === 'jpg') && $this->config->progressiveJpeg) {
            imageinterlace($image, true);
        }

        match ($format) {
            'jpeg', 'jpg' => imagejpeg($image, $path, $this->config->jpegQuality),
            'png' => imagepng($image, $path, 6),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, $this->config->webpQuality),
            'avif' => imageavif($image, $path, $this->config->avifQuality),
            default => throw CmsException::invalidImageFile(),
        };
    }
}
