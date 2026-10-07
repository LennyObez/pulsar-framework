<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\WatermarkConfig;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkPosition;

#[CoversClass(WatermarkConfig::class)]
final class WatermarkConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_sensible(): void
    {
        $config = new WatermarkConfig();

        self::assertFalse($config->enabled);
        self::assertNull($config->imagePath);
        self::assertNull($config->text);
        self::assertSame(WatermarkPosition::BottomRight, $config->position);
        self::assertSame(50, $config->opacity);
        self::assertSame(20, $config->scale);
        self::assertSame(10, $config->margin);
        self::assertSame('', $config->fontPath);
        self::assertSame(24, $config->fontSize);
        self::assertSame('#FFFFFF', $config->fontColor);
        self::assertSame([], $config->perVariant);
    }

    #[Test]
    public function fromArray_builds_from_config_data(): void
    {
        $config = WatermarkConfig::fromArray([
            'enabled' => true,
            'image_path' => '/var/www/watermark.png',
            'text' => 'Copyright 2025',
            'position' => 'center',
            'opacity' => 75,
            'scale' => 30,
            'margin' => 20,
            'font_path' => '/fonts/arial.ttf',
            'font_size' => 18,
            'font_color' => '#000000',
            'per_variant' => [
                'thumbnail' => false,
                'large' => true,
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('/var/www/watermark.png', $config->imagePath);
        self::assertSame('Copyright 2025', $config->text);
        self::assertSame(WatermarkPosition::Center, $config->position);
        self::assertSame(75, $config->opacity);
        self::assertSame(30, $config->scale);
        self::assertSame(20, $config->margin);
        self::assertSame('/fonts/arial.ttf', $config->fontPath);
        self::assertSame(18, $config->fontSize);
        self::assertSame('#000000', $config->fontColor);
        self::assertFalse($config->perVariant['thumbnail']);
        self::assertTrue($config->perVariant['large']);
    }

    #[Test]
    public function fromArray_clamps_opacity_to_valid_range(): void
    {
        $tooHigh = WatermarkConfig::fromArray(['opacity' => 200]);
        self::assertSame(100, $tooHigh->opacity);

        $tooLow = WatermarkConfig::fromArray(['opacity' => -10]);
        self::assertSame(0, $tooLow->opacity);
    }

    #[Test]
    public function fromArray_clamps_scale_to_valid_range(): void
    {
        $tooHigh = WatermarkConfig::fromArray(['scale' => 500]);
        self::assertSame(100, $tooHigh->scale);

        $tooLow = WatermarkConfig::fromArray(['scale' => 0]);
        self::assertSame(1, $tooLow->scale);
    }

    #[Test]
    public function fromArray_uses_default_position_for_invalid_value(): void
    {
        $config = WatermarkConfig::fromArray(['position' => 'invalid']);

        self::assertSame(WatermarkPosition::BottomRight, $config->position);
    }

    #[Test]
    public function fromArray_handles_empty_array(): void
    {
        $config = WatermarkConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertNull($config->imagePath);
    }

    #[Test]
    public function fromArray_ignores_invalid_per_variant_entries(): void
    {
        $config = WatermarkConfig::fromArray([
            'per_variant' => [
                'valid' => true,
                123 => true, // non-string key
                'also_valid' => false,
            ],
        ]);

        self::assertCount(2, $config->perVariant);
        self::assertTrue($config->perVariant['valid']);
        self::assertFalse($config->perVariant['also_valid']);
    }

    #[Test]
    public function shouldApplyToVariant_returns_false_when_disabled(): void
    {
        $config = new WatermarkConfig(enabled: false);

        self::assertFalse($config->shouldApplyToVariant('large'));
    }

    #[Test]
    public function shouldApplyToVariant_returns_true_when_enabled_and_no_overrides(): void
    {
        $config = new WatermarkConfig(enabled: true);

        self::assertTrue($config->shouldApplyToVariant('large'));
        self::assertTrue($config->shouldApplyToVariant('thumbnail'));
    }

    #[Test]
    public function shouldApplyToVariant_respects_per_variant_overrides(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            perVariant: ['thumbnail' => false, 'large' => true],
        );

        self::assertFalse($config->shouldApplyToVariant('thumbnail'));
        self::assertTrue($config->shouldApplyToVariant('large'));
    }

    #[Test]
    public function shouldApplyToVariant_defaults_to_true_for_unlisted_variant(): void
    {
        $config = new WatermarkConfig(
            enabled: true,
            perVariant: ['thumbnail' => false],
        );

        self::assertTrue($config->shouldApplyToVariant('medium'));
    }

    #[Test]
    #[DataProvider('positionProvider')]
    public function fromArray_parses_all_position_values(string $input, WatermarkPosition $expected): void
    {
        $config = WatermarkConfig::fromArray(['position' => $input]);

        self::assertSame($expected, $config->position);
    }

    /**
     * @return iterable<string, array{string, WatermarkPosition}>
     */
    public static function positionProvider(): iterable
    {
        yield 'top-left' => ['top-left', WatermarkPosition::TopLeft];
        yield 'top-center' => ['top-center', WatermarkPosition::TopCenter];
        yield 'top-right' => ['top-right', WatermarkPosition::TopRight];
        yield 'middle-left' => ['middle-left', WatermarkPosition::MiddleLeft];
        yield 'center' => ['center', WatermarkPosition::Center];
        yield 'middle-right' => ['middle-right', WatermarkPosition::MiddleRight];
        yield 'bottom-left' => ['bottom-left', WatermarkPosition::BottomLeft];
        yield 'bottom-center' => ['bottom-center', WatermarkPosition::BottomCenter];
        yield 'bottom-right' => ['bottom-right', WatermarkPosition::BottomRight];
    }
}
