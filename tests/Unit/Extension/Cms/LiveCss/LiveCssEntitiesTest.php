<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\LiveCss;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;
use Pulsar\Extension\Cms\LiveCss\ThemeToken;

#[CoversClass(CssOverride::class)]
#[CoversClass(CssValidationResult::class)]
#[CoversClass(LiveCssConfig::class)]
#[CoversClass(ThemeToken::class)]
final class LiveCssEntitiesTest extends TestCase
{
    // -- CssOverride ----------------------------------------------------------

    #[Test]
    public function cssOverrideCreateFactory(): void
    {
        $override = CssOverride::create(
            id: 'override-01',
            themeId: 'theme-aurora',
            version: 3,
            cssContent: ':root { --color-primary: #1a73e8; }',
            cssHash: hash('sha256', ':root { --color-primary: #1a73e8; }'),
            tokenOverrides: ['--color-primary' => '#1a73e8'],
            createdBy: 'user-admin',
            reason: 'Brand color update',
            tenantId: 'tenant-01',
        );

        self::assertSame('override-01', $override->id);
        self::assertSame('tenant-01', $override->tenantId);
        self::assertSame('theme-aurora', $override->themeId);
        self::assertSame(3, $override->version);
        self::assertTrue($override->isActive);
        self::assertSame('user-admin', $override->createdBy);
        self::assertSame('Brand color update', $override->reason);
        self::assertSame('#1a73e8', $override->tokenOverrides['--color-primary']);
    }

    #[Test]
    public function cssOverrideCreateWithoutTenant(): void
    {
        $override = CssOverride::create(
            id: 'override-02',
            themeId: 'theme-midnight',
            version: 1,
            cssContent: 'body { color: #333; }',
            cssHash: hash('sha256', 'body { color: #333; }'),
            tokenOverrides: [],
            createdBy: 'user-01',
            reason: 'Initial customization',
        );

        self::assertNull($override->tenantId);
        self::assertTrue($override->isActive);
    }

    // -- CssValidationResult --------------------------------------------------

    #[Test]
    public function cssValidationResultValid(): void
    {
        $result = new CssValidationResult(
            isValid: true,
            errors: [],
            sanitizedCss: 'body { color: #333; }',
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertSame('body { color: #333; }', $result->sanitizedCss);
    }

    #[Test]
    public function cssValidationResultInvalid(): void
    {
        $result = new CssValidationResult(
            isValid: false,
            errors: ['Expression function detected', 'External URL in @import'],
            sanitizedCss: 'body { }',
        );

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
    }

    // -- LiveCssConfig --------------------------------------------------------

    #[Test]
    public function liveCssConfigDefaults(): void
    {
        $config = new LiveCssConfig();

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }

    #[Test]
    public function liveCssConfigFromArray(): void
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
    public function liveCssConfigFromEmptyArray(): void
    {
        $config = LiveCssConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame(100_000, $config->maxCssLength);
        self::assertFalse($config->allowExternalFonts);
    }

    // -- ThemeToken -----------------------------------------------------------

    #[Test]
    public function themeTokenConstructor(): void
    {
        $token = new ThemeToken(
            name: '--color-primary',
            type: 'color',
            default: '#1a73e8',
            label: 'Primary Color',
            group: 'Colors',
            constraints: ['format' => 'hex'],
        );

        self::assertSame('--color-primary', $token->name);
        self::assertSame('color', $token->type);
        self::assertSame('#1a73e8', $token->default);
        self::assertSame('Primary Color', $token->label);
        self::assertSame('Colors', $token->group);
        self::assertSame('hex', $token->constraints['format']);
    }

    #[Test]
    public function themeTokenWithoutConstraints(): void
    {
        $token = new ThemeToken(
            name: '--font-body',
            type: 'font',
            default: 'Inter, sans-serif',
            label: 'Body Font',
            group: 'Typography',
        );

        self::assertSame([], $token->constraints);
    }
}
