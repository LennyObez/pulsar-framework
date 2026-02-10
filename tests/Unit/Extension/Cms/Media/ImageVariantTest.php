<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\ImageVariant;

#[CoversClass(ImageVariant::class)]
final class ImageVariantTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $variant = new ImageVariant(
            path: 'media/image-thumbnail.webp',
            width: 150,
            height: 100,
            format: 'webp',
            sizeBytes: 8192,
        );

        self::assertSame('media/image-thumbnail.webp', $variant->path);
        self::assertSame(150, $variant->width);
        self::assertSame(100, $variant->height);
        self::assertSame('webp', $variant->format);
        self::assertSame(8192, $variant->sizeBytes);
    }

    #[Test]
    public function fromArrayCreatesInstanceFromValidData(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => 'uploads/photo-medium.avif',
            'width' => 600,
            'height' => 400,
            'format' => 'avif',
            'size_bytes' => 24576,
        ]);

        self::assertSame('uploads/photo-medium.avif', $variant->path);
        self::assertSame(600, $variant->width);
        self::assertSame(400, $variant->height);
        self::assertSame('avif', $variant->format);
        self::assertSame(24576, $variant->sizeBytes);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $variant = ImageVariant::fromArray([]);

        self::assertSame('', $variant->path);
        self::assertSame(0, $variant->width);
        self::assertSame(0, $variant->height);
        self::assertSame('', $variant->format);
        self::assertSame(0, $variant->sizeBytes);
    }

    #[Test]
    public function fromArrayCastsTypes(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => 123,
            'width' => '600',
            'height' => '400',
            'format' => 456,
            'size_bytes' => '1024',
        ]);

        self::assertSame('123', $variant->path);
        self::assertSame(600, $variant->width);
        self::assertSame(400, $variant->height);
        self::assertSame('456', $variant->format);
        self::assertSame(1024, $variant->sizeBytes);
    }
}
