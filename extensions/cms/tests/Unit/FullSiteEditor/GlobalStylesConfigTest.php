<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\FullSiteEditor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\FullSiteEditor\GlobalStylesConfig;

final class GlobalStylesConfigTest extends TestCase
{
    #[Test]
    public function defaultsToEmptyTokens(): void
    {
        $config = new GlobalStylesConfig();

        self::assertSame([], $config->colors);
        self::assertSame([], $config->typography);
        self::assertSame([], $config->spacing);
        self::assertSame([], $config->borders);
        self::assertSame('', $config->customCss);
    }

    #[Test]
    public function fromArrayExtractsAllTokenGroups(): void
    {
        $config = GlobalStylesConfig::fromArray([
            'colors' => ['primary' => '#1e40af', 'secondary' => '#64748b'],
            'typography' => ['heading-font' => '"Inter", sans-serif'],
            'spacing' => ['section-gap' => '4rem'],
            'borders' => ['radius' => '0.5rem'],
            'custom_css' => 'body { line-height: 1.6; }',
        ]);

        self::assertSame('#1e40af', $config->colors['primary']);
        self::assertSame('#64748b', $config->colors['secondary']);
        self::assertSame('"Inter", sans-serif', $config->typography['heading-font']);
        self::assertSame('4rem', $config->spacing['section-gap']);
        self::assertSame('0.5rem', $config->borders['radius']);
        self::assertSame('body { line-height: 1.6; }', $config->customCss);
    }

    #[Test]
    public function fromArrayHandlesMissingKeys(): void
    {
        $config = GlobalStylesConfig::fromArray([]);

        self::assertSame([], $config->colors);
        self::assertSame('', $config->customCss);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $config = GlobalStylesConfig::fromArray([
            'colors' => ['valid' => '#fff', 'invalid' => 42, 'also_invalid' => null],
        ]);

        self::assertCount(1, $config->colors);
        self::assertSame('#fff', $config->colors['valid']);
    }

    #[Test]
    public function toCssGeneratesRootBlockWithAllTokens(): void
    {
        $config = new GlobalStylesConfig(
            colors: ['primary' => '#1e40af'],
            typography: ['body' => 'sans-serif'],
            spacing: ['md' => '1rem'],
            borders: ['radius' => '4px'],
        );

        $css = $config->toCss();

        self::assertStringContainsString(':root {', $css);
        self::assertStringContainsString('--color-primary: #1e40af;', $css);
        self::assertStringContainsString('--font-body: sans-serif;', $css);
        self::assertStringContainsString('--space-md: 1rem;', $css);
        self::assertStringContainsString('--border-radius: 4px;', $css);
    }

    #[Test]
    public function toCssReturnsEmptyStringWhenNoTokens(): void
    {
        $config = new GlobalStylesConfig();

        self::assertSame('', $config->toCss());
    }

    #[Test]
    public function toCssAppendsCustomCss(): void
    {
        $config = new GlobalStylesConfig(
            colors: ['bg' => '#fff'],
            customCss: '.custom { display: flex; }',
        );

        $css = $config->toCss();

        self::assertStringContainsString('--color-bg: #fff;', $css);
        self::assertStringContainsString('.custom { display: flex; }', $css);
    }

    #[Test]
    public function toCssWithOnlyCustomCssOutputsOnlyCustomBlock(): void
    {
        $config = new GlobalStylesConfig(customCss: 'body { margin: 0; }');

        $css = $config->toCss();

        self::assertStringNotContainsString(':root', $css);
        self::assertStringContainsString('body { margin: 0; }', $css);
    }
}
