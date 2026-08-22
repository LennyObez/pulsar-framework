<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

#[CoversClass(PluginManifest::class)]
final class PluginManifestFromArrayTest extends TestCase
{
    #[Test]
    public function fromArrayWithFullData(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'seo-toolkit',
            'display_name' => 'SEO Toolkit',
            'version' => '1.2.3',
            'description' => 'Enhanced SEO features',
            'author_name' => 'Plugin Author',
            'author_url' => 'https://author.example.com',
            'license' => 'MIT',
            'pulsar_version' => '>=1.0.0',
            'capabilities' => ['seo', 'analytics'],
            'dependencies' => ['analytics' => '>=2.0.0'],
            'entry_point' => 'Vendor\\SeoToolkit\\SeoPlugin',
            'settings' => ['auto_meta' => true],
            'autoload' => ['psr-4' => ['Vendor\\SeoToolkit\\' => 'src/']],
        ]);

        self::assertSame('seo-toolkit', $manifest->slug);
        self::assertSame('SEO Toolkit', $manifest->displayName);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame('Enhanced SEO features', $manifest->description);
        self::assertSame('Plugin Author', $manifest->authorName);
        self::assertSame('https://author.example.com', $manifest->authorUrl);
        self::assertSame('MIT', $manifest->license);
        self::assertSame('>=1.0.0', $manifest->pulsarVersionConstraint);
        self::assertSame(['seo', 'analytics'], $manifest->capabilities);
        self::assertSame(['analytics' => '>=2.0.0'], $manifest->dependencies);
        self::assertSame('Vendor\\SeoToolkit\\SeoPlugin', $manifest->entryPoint);
        self::assertSame(['auto_meta' => true], $manifest->settings);
        self::assertNotNull($manifest->autoload);
    }

    #[Test]
    public function fromArrayWithMinimalData(): void
    {
        $manifest = PluginManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->entryPoint);
        self::assertSame([], $manifest->capabilities);
        self::assertSame([], $manifest->dependencies);
        self::assertNull($manifest->autoload);
    }

    #[Test]
    public function fromArrayFallsBackToNameField(): void
    {
        $manifest = PluginManifest::fromArray([
            'name' => 'Fallback Plugin Name',
        ]);

        self::assertSame('Fallback Plugin Name', $manifest->displayName);
    }

    #[Test]
    public function fromArrayHandlesNonArrayCapabilities(): void
    {
        $manifest = PluginManifest::fromArray([
            'capabilities' => 'not-an-array',
        ]);

        self::assertSame([], $manifest->capabilities);
    }
}
