<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Themes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Themes\ThemeManifest;

#[CoversClass(ThemeManifest::class)]
final class ThemeManifestFromArrayTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $manifest = ThemeManifest::fromArray([
            'slug' => 'default-theme',
            'display_name' => 'Default Theme',
            'version' => '2.1.0',
            'description' => 'A clean theme',
            'author_name' => 'Pulsar Team',
            'author_url' => 'https://pulsar.dev',
            'license' => 'MIT',
            'pulsar_version' => '>=1.0.0',
            'parent_theme' => 'base',
            'regions' => ['header', 'content', 'footer'],
            'supported_content_types' => ['page', 'post'],
            'settings' => ['primary_color' => '#000'],
            'assets' => ['css' => 'assets/theme.css'],
        ]);

        self::assertSame('default-theme', $manifest->slug);
        self::assertSame('Default Theme', $manifest->displayName);
        self::assertSame('2.1.0', $manifest->version);
        self::assertSame('A clean theme', $manifest->description);
        self::assertSame('Pulsar Team', $manifest->authorName);
        self::assertSame('https://pulsar.dev', $manifest->authorUrl);
        self::assertSame('MIT', $manifest->license);
        self::assertSame('>=1.0.0', $manifest->pulsarVersionConstraint);
        self::assertSame('base', $manifest->parentTheme);
        self::assertSame(['header', 'content', 'footer'], $manifest->regions);
        self::assertSame(['page', 'post'], $manifest->supportedContentTypes);
        self::assertSame(['primary_color' => '#000'], $manifest->settings);
        self::assertSame(['css' => 'assets/theme.css'], $manifest->assets);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $manifest = ThemeManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->authorName);
        self::assertNull($manifest->license);
        self::assertNull($manifest->parentTheme);
        self::assertSame([], $manifest->regions);
        self::assertSame([], $manifest->assets);
    }

    #[Test]
    public function fromArrayFallsBackToNameField(): void
    {
        $manifest = ThemeManifest::fromArray([
            'name' => 'Fallback Name',
        ]);

        self::assertSame('Fallback Name', $manifest->displayName);
    }

    #[Test]
    public function fromArrayHandlesNonStringRegions(): void
    {
        $manifest = ThemeManifest::fromArray([
            'regions' => ['header', 42, true],
        ]);

        self::assertSame(['header', '42', '1'], $manifest->regions);
    }
}
