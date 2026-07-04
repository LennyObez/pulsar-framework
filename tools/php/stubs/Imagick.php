<?php

/**
 * Stub for ext-imagick (optional runtime extension).
 *
 * Provides type declarations for Psalm static analysis so the result is
 * identical whether or not ext-imagick is loaded in the analysing
 * environment. The real classes are provided by ext-imagick at runtime;
 * the source always guards usage behind class_exists(Imagick::class).
 *
 * Coverage is scoped to the symbols used by:
 *   extensions/cms/src/Media/Processing/ImagickImageProcessor.php
 *   extensions/cms/src/Media/Document/PdfThumbnailGenerator.php
 * Psalm flags any new un-stubbed usage, so the stub cannot drift undetected.
 * Constant values mirror ext-imagick reflection.
 */

class Imagick
{
    public const int COLORSPACE_CMYK = 2;
    public const int COLORSPACE_SRGB = 23;
    public const int FILTER_LANCZOS = 22;
    public const int INTERLACE_PLANE = 3;
    public const int COMPOSITE_OVER = 54;
    public const int CHANNEL_DEFAULT = 134217727;

    public function __construct(array|string|null $files = null) {}

    public function getImageColorspace(): int
    {
        return 0;
    }

    public function transformImageColorspace(int $colorspace): bool
    {
        return true;
    }

    public function getImageWidth(): int
    {
        return 0;
    }

    public function getImageHeight(): int
    {
        return 0;
    }

    public function resizeImage(int $columns, int $rows, int $filterType, float $blur, bool $bestfit = false): bool
    {
        return true;
    }

    public function setInterlaceScheme(int $interlace): bool
    {
        return true;
    }

    public function writeImage(?string $filename = null): bool
    {
        return true;
    }

    public function destroy(): bool
    {
        return true;
    }

    public function setImageFormat(string $format): bool
    {
        return true;
    }

    public function setImageCompressionQuality(int $quality): bool
    {
        return true;
    }

    public function getImageBlob(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getImageProfiles(string $pattern = '*', bool $include_values = true): array
    {
        return [];
    }

    public function stripImage(): bool
    {
        return true;
    }

    public function profileImage(string $name, ?string $profile): bool
    {
        return true;
    }

    public function setResolution(float $x_resolution, float $y_resolution): bool
    {
        return true;
    }

    public function readImage(string $filename): bool
    {
        return true;
    }

    public function thumbnailImage(int $columns, ?int $rows, bool $bestfit = false, bool $fill = false): bool
    {
        return true;
    }

    public function newImage(int $columns, int $rows, ImagickPixel|string $background, string $format = ''): bool
    {
        return true;
    }

    public function compositeImage(Imagick $composite_object, int $composite, int $x, int $y, int $channel = Imagick::CHANNEL_DEFAULT): bool
    {
        return true;
    }
}

class ImagickPixel
{
    public function __construct(string $color = '') {}
}

class ImagickException extends \Exception {}
