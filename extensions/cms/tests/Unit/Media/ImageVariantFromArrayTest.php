<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\ImageVariant;

#[CoversClass(ImageVariant::class)]
final class ImageVariantFromArrayTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $variant = ImageVariant::fromArray([
            'path' => '/uploads/img_thumb.webp',
            'width' => 300,
            'height' => 200,
            'format' => 'webp',
            'size_bytes' => 45000,
        ]);

        self::assertSame('/uploads/img_thumb.webp', $variant->path);
        self::assertSame(300, $variant->width);
        self::assertSame(200, $variant->height);
        self::assertSame('webp', $variant->format);
        self::assertSame(45000, $variant->sizeBytes);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $variant = ImageVariant::fromArray([]);

        self::assertSame('', $variant->path);
        self::assertSame(0, $variant->width);
        self::assertSame(0, $variant->height);
        self::assertSame('', $variant->format);
        self::assertSame(0, $variant->sizeBytes);
    }

    #[Test]
    public function fromArrayWithNumericStringDimensions(): void
    {
        $variant = ImageVariant::fromArray([
            'width' => '1024',
            'height' => '768',
            'size_bytes' => '500000',
        ]);

        self::assertSame(1024, $variant->width);
        self::assertSame(768, $variant->height);
        self::assertSame(500000, $variant->sizeBytes);
    }
}
