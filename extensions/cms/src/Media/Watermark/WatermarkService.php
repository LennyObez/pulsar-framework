<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Media\Watermark;

use GdImage;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\WatermarkConfig;

use function ceil;
use function file_exists;
use function getimagesize;
use function hexdec;
use function imagecolorallocatealpha;
use function imagecopymerge;
use function imagecopyresampled;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagecreatefromwebp;
use function imagecreatetruecolor;
use function imagejpeg;
use function imagepng;
use function imagesx;
use function imagesy;
use function imagettfbbox;
use function imagettftext;
use function imagewebp;
use function intval;
use function max;
use function min;
use function pathinfo;
use function strlen;
use function strtolower;
use function substr;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;
use const PATHINFO_EXTENSION;

/**
 * Applies image or text watermarks to derivative images.
 *
 * Watermarks are applied only to display variants, never to stored originals.
 * Supports configurable position (9-point grid), opacity, and scale.
 */
#[Internal(reason: 'Internal watermarking implementation')]
final readonly class WatermarkService
{
    public function __construct(
        private WatermarkConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Apply watermark to an image file in-place.
     *
     * @param string $imagePath Path to the image to watermark
     * @param string $variantName Variant name for per-variant config check
     *
     * @return bool Whether the watermark was applied
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function apply(string $imagePath, string $variantName): bool
    {
        if (!$this->config->shouldApplyToVariant($variantName)) {
            return false;
        }

        $targetImage = $this->loadImage($imagePath);

        if ($targetImage === null) {
            return false;
        }

        $applied = false;

        if ($this->config->imagePath !== null && file_exists($this->config->imagePath)) {
            $applied = $this->applyImageWatermark($targetImage);
        } elseif ($this->config->text !== null && $this->config->text !== '') {
            $applied = $this->applyTextWatermark($targetImage);
        }

        if ($applied) {
            $this->saveImage($targetImage, $imagePath);
        }

        return $applied;
    }

    private function applyImageWatermark(GdImage $target): bool
    {
        $watermarkImage = $this->loadImage($this->config->imagePath ?? '');

        if ($watermarkImage === null) {
            $this->logger->warning('Failed to load watermark image', [
                'path' => $this->config->imagePath,
            ]);

            return false;
        }

        $targetWidth = imagesx($target);
        $targetHeight = imagesy($target);
        $wmWidth = imagesx($watermarkImage);
        $wmHeight = imagesy($watermarkImage);

        // Scale watermark relative to target image
        $shortestDimension = min($targetWidth, $targetHeight);
        $scaledSize = (int) ceil((float) $shortestDimension * (float) $this->config->scale / 100.0);

        // Maintain watermark aspect ratio
        $ratio = (float) $wmWidth / (float) $wmHeight;
        $newWmWidth = $scaledSize;
        $newWmHeight = (int) ceil((float) $scaledSize / $ratio);

        if ($newWmHeight > $scaledSize) {
            $newWmHeight = $scaledSize;
            $newWmWidth = (int) ceil((float) $scaledSize * $ratio);
        }

        // Create scaled watermark
        $scaledWatermark = imagecreatetruecolor(max(1, $newWmWidth), max(1, $newWmHeight));

        if ($scaledWatermark === false) {
            return false;
        }

        imagealphablending($scaledWatermark, false);
        imagesavealpha($scaledWatermark, true);
        $transparent = imagecolorallocatealpha($scaledWatermark, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefill($scaledWatermark, 0, 0, $transparent);
        }

        imagecopyresampled(
            $scaledWatermark,
            $watermarkImage,
            0,
            0,
            0,
            0,
            $newWmWidth,
            $newWmHeight,
            $wmWidth,
            $wmHeight,
        );

        // Calculate position
        [$x, $y] = $this->calculatePosition(
            $targetWidth,
            $targetHeight,
            $newWmWidth,
            $newWmHeight,
        );

        // Apply with opacity
        imagecopymerge(
            $target,
            $scaledWatermark,
            $x,
            $y,
            0,
            0,
            $newWmWidth,
            $newWmHeight,
            $this->config->opacity,
        );

        return true;
    }

    private function applyTextWatermark(GdImage $target): bool
    {
        $text = $this->config->text ?? '';

        if ($text === '') {
            return false;
        }

        $targetWidth = imagesx($target);
        $targetHeight = imagesy($target);

        $color = $this->parseHexColor($target, $this->config->fontColor, $this->config->opacity);

        if ($color === false) {
            return false;
        }

        // Use TTF font if available, otherwise fall back to built-in
        if ($this->config->fontPath !== '' && file_exists($this->config->fontPath)) {
            return $this->applyTtfTextWatermark($target, $text, $color, $targetWidth, $targetHeight);
        }

        // Built-in font fallback: position based on config
        $textWidth = imagefontwidth(5) * strlen($text);
        $textHeight = imagefontheight(5);

        [$x, $y] = $this->calculatePosition($targetWidth, $targetHeight, $textWidth, $textHeight);

        imagestring($target, 5, $x, $y, $text, $color);

        return true;
    }

    private function applyTtfTextWatermark(
        GdImage $target,
        string $text,
        int $color,
        int $targetWidth,
        int $targetHeight,
    ): bool {
        $bbox = @imagettfbbox((float) $this->config->fontSize, 0.0, $this->config->fontPath, $text);

        if ($bbox === false) {
            $this->logger->warning('Failed to calculate text bounding box', [
                'font' => $this->config->fontPath,
            ]);

            return false;
        }

        /** @var array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int} $bbox */
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];

        [$x, $y] = $this->calculatePosition($targetWidth, $targetHeight, $textWidth, $textHeight);

        // imagettftext uses bottom-left origin for Y
        $y += $textHeight;

        @imagettftext(
            $target,
            (float) $this->config->fontSize,
            0.0,
            $x,
            $y,
            $color,
            $this->config->fontPath,
            $text,
        );

        return true;
    }

    /**
     * Calculate x, y placement based on position enum and margin.
     *
     * @return array{0: int, 1: int}
     */
    private function calculatePosition(
        int $targetWidth,
        int $targetHeight,
        int $wmWidth,
        int $wmHeight,
    ): array {
        $margin = $this->config->margin;

        $x = match ($this->config->position) {
            WatermarkPosition::TopLeft,
            WatermarkPosition::MiddleLeft,
            WatermarkPosition::BottomLeft => $margin,
            WatermarkPosition::TopCenter,
            WatermarkPosition::Center,
            WatermarkPosition::BottomCenter => (int) (($targetWidth - $wmWidth) / 2),
            WatermarkPosition::TopRight,
            WatermarkPosition::MiddleRight,
            WatermarkPosition::BottomRight => $targetWidth - $wmWidth - $margin,
        };

        $y = match ($this->config->position) {
            WatermarkPosition::TopLeft,
            WatermarkPosition::TopCenter,
            WatermarkPosition::TopRight => $margin,
            WatermarkPosition::MiddleLeft,
            WatermarkPosition::Center,
            WatermarkPosition::MiddleRight => (int) (($targetHeight - $wmHeight) / 2),
            WatermarkPosition::BottomLeft,
            WatermarkPosition::BottomCenter,
            WatermarkPosition::BottomRight => $targetHeight - $wmHeight - $margin,
        };

        return [max(0, $x), max(0, $y)];
    }

    private function parseHexColor(GdImage $image, string $hex, int $opacityPercent): int|false
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        /** @var int<0, 255> $r */
        $r = min(255, max(0, intval(hexdec(substr($hex, 0, 2)))));
        /** @var int<0, 255> $g */
        $g = min(255, max(0, intval(hexdec(substr($hex, 2, 2)))));
        /** @var int<0, 255> $b */
        $b = min(255, max(0, intval(hexdec(substr($hex, 4, 2)))));

        // Convert percentage opacity to GD alpha (0 = opaque, 127 = transparent)
        /** @var int<0, 127> $alpha */
        $alpha = min(127, max(0, (int) ((100 - $opacityPercent) * 127 / 100)));

        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }

    private function loadImage(string $path): ?GdImage
    {
        if (!file_exists($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        imagealphablending($image, true);

        return $image;
    }

    private function saveImage(GdImage $image, string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        match ($ext) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path, 85),
        };
    }
}
