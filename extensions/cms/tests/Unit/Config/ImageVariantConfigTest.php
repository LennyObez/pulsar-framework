<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\ImageVariantConfig;

#[CoversClass(ImageVariantConfig::class)]
final class ImageVariantConfigTest extends TestCase
{
    #[Test]
    public function constructorStoresValues(): void
    {
        $config = new ImageVariantConfig(
            name: 'thumbnail',
            maxWidth: 150,
            maxHeight: 150,
            format: 'webp',
            quality: 70,
        );

        self::assertSame('thumbnail', $config->name);
        self::assertSame(150, $config->maxWidth);
        self::assertSame(150, $config->maxHeight);
        self::assertSame('webp', $config->format);
        self::assertSame(70, $config->quality);
    }

    #[Test]
    public function constructorDefaultsFormatAndQuality(): void
    {
        $config = new ImageVariantConfig(name: 'hero', maxWidth: 1920, maxHeight: 1080);

        self::assertSame('original', $config->format);
        self::assertSame(80, $config->quality);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = ImageVariantConfig::fromArray([
            'name' => 'medium',
            'max_width' => 800,
            'max_height' => 600,
            'format' => 'avif',
            'quality' => 50,
        ]);

        self::assertSame('medium', $config->name);
        self::assertSame(800, $config->maxWidth);
        self::assertSame(600, $config->maxHeight);
        self::assertSame('avif', $config->format);
        self::assertSame(50, $config->quality);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ImageVariantConfig::fromArray([]);

        self::assertSame('', $config->name);
        self::assertSame(0, $config->maxWidth);
        self::assertSame(0, $config->maxHeight);
        self::assertSame('original', $config->format);
        self::assertSame(80, $config->quality);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = ImageVariantConfig::fromArray([
            'name' => 42,
            'max_width' => '800',
            'max_height' => 600.5,
            'format' => false,
            'quality' => 'high',
        ]);

        self::assertSame('', $config->name);
        self::assertSame(0, $config->maxWidth);
        self::assertSame(0, $config->maxHeight);
        self::assertSame('original', $config->format);
        self::assertSame(80, $config->quality);
    }
}
