<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\WatermarkConfig;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkPosition;

#[CoversClass(WatermarkConfig::class)]
final class WatermarkConfigShouldApplyTest extends TestCase
{
    #[Test]
    public function shouldApplyToVariantReturnsFalseWhenDisabled(): void
    {
        $config = new WatermarkConfig(enabled: false);

        self::assertFalse($config->shouldApplyToVariant('thumbnail'));
    }

    #[Test]
    public function shouldApplyToVariantReturnsTrueWhenEnabledAndNoOverrides(): void
    {
        $config = new WatermarkConfig(enabled: true, perVariant: []);

        self::assertTrue($config->shouldApplyToVariant('thumbnail'));
    }

    #[Test]
    public function shouldApplyToVariantRespectsPerVariantOverride(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            perVariant: ['thumbnail' => false, 'large' => true],
        );

        self::assertFalse($config->shouldApplyToVariant('thumbnail'));
        self::assertTrue($config->shouldApplyToVariant('large'));
    }

    #[Test]
    public function shouldApplyToVariantDefaultsTrueForUnlistedVariant(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            perVariant: ['thumbnail' => false],
        );

        self::assertTrue($config->shouldApplyToVariant('medium'));
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = WatermarkConfig::fromArray([
            'enabled' => true,
            'image_path' => '/img/watermark.png',
            'text' => 'Copyright',
            'position' => 'top-left',
            'opacity' => 80,
            'scale' => 30,
            'margin' => 20,
            'font_path' => '/fonts/mono.ttf',
            'font_size' => 18,
            'font_color' => '#000000',
            'per_variant' => ['thumbnail' => false],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('/img/watermark.png', $config->imagePath);
        self::assertSame('Copyright', $config->text);
        self::assertSame(WatermarkPosition::TopLeft, $config->position);
        self::assertSame(80, $config->opacity);
        self::assertSame(30, $config->scale);
        self::assertSame(20, $config->margin);
        self::assertSame('/fonts/mono.ttf', $config->fontPath);
        self::assertSame(18, $config->fontSize);
        self::assertSame('#000000', $config->fontColor);
        self::assertSame(['thumbnail' => false], $config->perVariant);
    }

    #[Test]
    public function fromArrayClampsOpacityToRange(): void
    {
        $low = WatermarkConfig::fromArray(['opacity' => -10]);
        $high = WatermarkConfig::fromArray(['opacity' => 200]);

        self::assertSame(0, $low->opacity);
        self::assertSame(100, $high->opacity);
    }

    #[Test]
    public function fromArrayClampsScaleToRange(): void
    {
        $low = WatermarkConfig::fromArray(['scale' => 0]);
        $high = WatermarkConfig::fromArray(['scale' => 200]);

        self::assertSame(1, $low->scale);
        self::assertSame(100, $high->scale);
    }

    #[Test]
    public function fromArrayFallsBackToBottomRightForInvalidPosition(): void
    {
        $config = WatermarkConfig::fromArray(['position' => 'invalid']);

        self::assertSame(WatermarkPosition::BottomRight, $config->position);
    }
}
