<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Themes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\ThemeManifest;

#[CoversClass(ThemeManifest::class)]
final class ThemeManifestTest extends TestCase
{
    #[Test]
    public function fromArray_with_complete_data(): void
    {
        $manifest = ThemeManifest::fromArray([
            'slug' => 'horizon',
            'display_name' => 'Horizon Theme',
            'version' => '3.0.0',
            'description' => 'A modern theme',
            'author_name' => 'Designer Co',
            'author_url' => 'https://designer.co',
            'license' => 'GPL-3.0',
            'pulsar_version' => '>=1.0.0',
            'parent_theme' => 'base-theme',
            'regions' => ['header', 'footer', 'sidebar'],
            'supported_content_types' => ['article', 'page'],
            'settings' => ['primary_color' => '#000'],
            'assets' => ['style' => 'dist/style.css'],
        ]);

        self::assertSame('horizon', $manifest->slug);
        self::assertSame('Horizon Theme', $manifest->displayName);
        self::assertSame('3.0.0', $manifest->version);
        self::assertSame('A modern theme', $manifest->description);
        self::assertSame('Designer Co', $manifest->authorName);
        self::assertSame('https://designer.co', $manifest->authorUrl);
        self::assertSame('GPL-3.0', $manifest->license);
        self::assertSame('>=1.0.0', $manifest->pulsarVersionConstraint);
        self::assertSame('base-theme', $manifest->parentTheme);
        self::assertSame(['header', 'footer', 'sidebar'], $manifest->regions);
        self::assertSame(['article', 'page'], $manifest->supportedContentTypes);
        self::assertSame(['primary_color' => '#000'], $manifest->settings);
        self::assertSame(['style' => 'dist/style.css'], $manifest->assets);
    }

    #[Test]
    public function fromArray_with_empty_data_returns_safe_defaults(): void
    {
        $manifest = ThemeManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->authorName);
        self::assertNull($manifest->authorUrl);
        self::assertNull($manifest->license);
        self::assertNull($manifest->pulsarVersionConstraint);
        self::assertNull($manifest->parentTheme);
        self::assertSame([], $manifest->regions);
        self::assertSame([], $manifest->supportedContentTypes);
        self::assertSame([], $manifest->settings);
        self::assertSame([], $manifest->assets);
    }

    #[Test]
    public function fromArray_falls_back_to_name_key(): void
    {
        $manifest = ThemeManifest::fromArray(['name' => 'Name Fallback']);

        self::assertSame('Name Fallback', $manifest->displayName);
    }

    #[Test]
    public function fromArray_prefers_display_name_over_name(): void
    {
        $manifest = ThemeManifest::fromArray([
            'display_name' => 'Preferred',
            'name' => 'Fallback',
        ]);

        self::assertSame('Preferred', $manifest->displayName);
    }

    #[Test]
    public function fromArray_handles_non_array_regions(): void
    {
        $manifest = ThemeManifest::fromArray(['regions' => 'not-an-array']);

        self::assertSame([], $manifest->regions);
    }

    #[Test]
    public function fromArray_handles_non_array_assets(): void
    {
        $manifest = ThemeManifest::fromArray(['assets' => 'invalid']);

        self::assertSame([], $manifest->assets);
    }

    #[Test]
    public function fromArray_handles_non_string_values_in_regions(): void
    {
        $manifest = ThemeManifest::fromArray([
            'regions' => [123, true, 'header'],
        ]);

        self::assertSame(['123', '1', 'header'], $manifest->regions);
    }
}
