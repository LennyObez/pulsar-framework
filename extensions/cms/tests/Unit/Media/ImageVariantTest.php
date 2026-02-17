<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\ImageVariant;

#[CoversClass(ImageVariant::class)]
final class ImageVariantTest extends TestCase
{
    #[Test]
    public function constructor_stores_all_fields(): void
    {
        $variant = new ImageVariant(
            path: '/images/thumb.webp',
            width: 300,
            height: 200,
            format: 'webp',
            sizeBytes: 15000,
        );

        self::assertSame('/images/thumb.webp', $variant->path);
        self::assertSame(300, $variant->width);
        self::assertSame(200, $variant->height);
        self::assertSame('webp', $variant->format);
        self::assertSame(15000, $variant->sizeBytes);
    }

    #[Test]
    public function fromArray_with_complete_data(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => '/img/photo.jpg',
            'width' => 1920,
            'height' => 1080,
            'format' => 'jpeg',
            'size_bytes' => 500000,
        ]);

        self::assertSame('/img/photo.jpg', $variant->path);
        self::assertSame(1920, $variant->width);
        self::assertSame(1080, $variant->height);
        self::assertSame('jpeg', $variant->format);
        self::assertSame(500000, $variant->sizeBytes);
    }

    #[Test]
    public function fromArray_with_missing_keys_uses_defaults(): void
    {
        $variant = ImageVariant::fromArray([]);

        self::assertSame('', $variant->path);
        self::assertSame(0, $variant->width);
        self::assertSame(0, $variant->height);
        self::assertSame('', $variant->format);
        self::assertSame(0, $variant->sizeBytes);
    }

    #[Test]
    public function fromArray_with_non_string_path_defaults_to_empty(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => 123,
            'width' => 'abc',
            'height' => null,
            'format' => 42,
            'size_bytes' => 'not a number',
        ]);

        self::assertSame('', $variant->path);
        self::assertSame(0, $variant->width);
        self::assertSame(0, $variant->height);
        self::assertSame('', $variant->format);
        self::assertSame(0, $variant->sizeBytes);
    }

    #[Test]
    public function fromArray_with_numeric_string_values(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => '/path',
            'width' => '800',
            'height' => '600',
            'format' => 'png',
            'size_bytes' => '25000',
        ]);

        self::assertSame(800, $variant->width);
        self::assertSame(600, $variant->height);
        self::assertSame(25000, $variant->sizeBytes);
    }
}
