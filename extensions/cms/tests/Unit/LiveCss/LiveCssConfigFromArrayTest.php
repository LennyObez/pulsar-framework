<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;

#[CoversClass(LiveCssConfig::class)]
final class LiveCssConfigFromArrayTest extends TestCase
{
    #[Test]
    public function defaultsEnableEditor(): void
    {
        $config = new LiveCssConfig();

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }

    #[Test]
    public function fromArraySetsAllFields(): void
    {
        $config = LiveCssConfig::fromArray([
            'enabled' => false,
            'max_css_length' => 50_000,
            'allow_external_fonts' => true,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(50_000, $config->maxCssLength);
        self::assertTrue($config->allowExternalFonts);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = LiveCssConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }
}
