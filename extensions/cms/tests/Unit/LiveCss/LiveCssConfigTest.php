<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\LiveCss;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;

final class LiveCssConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new LiveCssConfig();

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }

    #[Test]
    public function custom_values(): void
    {
        $config = new LiveCssConfig(
            enabled: false,
            maxCssLength: 50_000,
            allowExternalFonts: true,
        );

        self::assertFalse($config->enabled);
        self::assertSame(50_000, $config->maxCssLength);
        self::assertTrue($config->allowExternalFonts);
    }

    #[Test]
    public function from_array_with_all_keys(): void
    {
        $config = LiveCssConfig::fromArray([
            'enabled' => false,
            'max_css_length' => 200_000,
            'allow_external_fonts' => true,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(200_000, $config->maxCssLength);
        self::assertTrue($config->allowExternalFonts);
    }

    #[Test]
    public function from_array_with_empty_array_uses_defaults(): void
    {
        $config = LiveCssConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }

    #[Test]
    public function from_array_ignores_invalid_types(): void
    {
        $config = LiveCssConfig::fromArray([
            'enabled' => 'not-a-bool',
            'max_css_length' => 'not-an-int',
            'allow_external_fonts' => 'not-a-bool',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }
}
