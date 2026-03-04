<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\MediaConfig;

#[CoversClass(MediaConfig::class)]
final class MediaConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreSecureAndReasonable(): void
    {
        $config = new MediaConfig();

        self::assertSame('local', $config->disk);
        self::assertSame(52_428_800, $config->maxUploadSize);
        self::assertContains('image/jpeg', $config->allowedMimeTypes);
        self::assertContains('image/svg+xml', $config->allowedMimeTypes);
        self::assertContains('application/pdf', $config->allowedMimeTypes);
        self::assertCount(7, $config->allowedMimeTypes);
        self::assertContains('jpg', $config->allowedExtensions);
        self::assertContains('svg', $config->allowedExtensions);
        self::assertCount(8, $config->allowedExtensions);
        self::assertSame(16384, $config->maxImageWidth);
        self::assertSame(16384, $config->maxImageHeight);
        self::assertSame(100_000_000, $config->maxPixelCount);
        self::assertFalse($config->preserveExif);
        self::assertSame(80, $config->webpQuality);
        self::assertSame(60, $config->avifQuality);
        self::assertTrue($config->avifEnabled);
        self::assertSame('storage/cms/media', $config->storagePath);
        self::assertSame([], $config->imageVariants);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = MediaConfig::fromArray([
            'disk' => 's3',
            'max_upload_size' => 5_242_880,
            'allowed_mime_types' => ['image/png', 'image/webp'],
            'allowed_extensions' => ['png', 'webp'],
            'max_image_width' => 4096,
            'max_image_height' => 4096,
            'max_pixel_count' => 16_000_000,
            'preserve_exif' => true,
            'webp_quality' => 90,
            'avif_quality' => 75,
            'avif_enabled' => false,
            'storage_path' => '/data/media',
            'image_variants' => [
                ['name' => 'thumb', 'max_width' => 150, 'max_height' => 150, 'format' => 'webp', 'quality' => 70],
                ['name' => 'large', 'max_width' => 1200, 'max_height' => 900],
            ],
        ]);

        self::assertSame('s3', $config->disk);
        self::assertSame(5_242_880, $config->maxUploadSize);
        self::assertSame(['image/png', 'image/webp'], $config->allowedMimeTypes);
        self::assertSame(['png', 'webp'], $config->allowedExtensions);
        self::assertSame(4096, $config->maxImageWidth);
        self::assertSame(4096, $config->maxImageHeight);
        self::assertSame(16_000_000, $config->maxPixelCount);
        self::assertTrue($config->preserveExif);
        self::assertSame(90, $config->webpQuality);
        self::assertSame(75, $config->avifQuality);
        self::assertFalse($config->avifEnabled);
        self::assertSame('/data/media', $config->storagePath);
        self::assertCount(2, $config->imageVariants);
        self::assertSame('thumb', $config->imageVariants[0]->name);
        self::assertSame('webp', $config->imageVariants[0]->format);
        self::assertSame('large', $config->imageVariants[1]->name);
        self::assertSame('original', $config->imageVariants[1]->format);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = MediaConfig::fromArray([]);

        self::assertSame('local', $config->disk);
        self::assertSame(52_428_800, $config->maxUploadSize);
        self::assertCount(7, $config->allowedMimeTypes);
        self::assertCount(8, $config->allowedExtensions);
        self::assertSame([], $config->imageVariants);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = MediaConfig::fromArray([
            'disk' => 42,
            'max_upload_size' => 'big',
            'allowed_mime_types' => 'not-array',
            'allowed_extensions' => false,
            'max_image_width' => 'wide',
            'preserve_exif' => 'yes',
            'webp_quality' => 80.5,
            'avif_enabled' => 1,
            'storage_path' => 123,
            'image_variants' => 'not-array',
        ]);

        self::assertSame('local', $config->disk);
        self::assertSame(52_428_800, $config->maxUploadSize);
        self::assertCount(7, $config->allowedMimeTypes);
        self::assertCount(8, $config->allowedExtensions);
        self::assertSame(16384, $config->maxImageWidth);
        self::assertFalse($config->preserveExif);
        self::assertSame(80, $config->webpQuality);
        self::assertTrue($config->avifEnabled);
        self::assertSame('storage/cms/media', $config->storagePath);
        self::assertSame([], $config->imageVariants);
    }

    #[Test]
    public function fromArraySkipsNonArrayVariantEntries(): void
    {
        $config = MediaConfig::fromArray([
            'image_variants' => [
                ['name' => 'thumb', 'max_width' => 100, 'max_height' => 100],
                'not-an-array',
                42,
                ['name' => 'large', 'max_width' => 800, 'max_height' => 600],
            ],
        ]);

        self::assertCount(2, $config->imageVariants);
        self::assertSame('thumb', $config->imageVariants[0]->name);
        self::assertSame('large', $config->imageVariants[1]->name);
    }

    #[Test]
    public function toStringListConvertsNonStringsToEmpty(): void
    {
        $config = MediaConfig::fromArray([
            'allowed_mime_types' => ['image/png', 42, true, 'image/gif'],
        ]);

        self::assertSame(['image/png', '', '', 'image/gif'], $config->allowedMimeTypes);
    }
}
