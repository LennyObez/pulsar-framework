<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

#[CoversClass(PluginManifest::class)]
final class PluginManifestTest extends TestCase
{
    #[Test]
    public function fromArray_with_complete_data(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'seo-optimizer',
            'display_name' => 'SEO Optimizer',
            'version' => '2.1.0',
            'description' => 'Optimizes content for search engines',
            'author_name' => 'Acme Corp',
            'author_url' => 'https://acme.com',
            'license' => 'MIT',
            'pulsar_version' => '>=1.0.0',
            'capabilities' => ['hooks', 'admin_pages'],
            'dependencies' => ['analytics' => '^1.0'],
            'entry_point' => 'Acme\\SeoPlugin',
            'settings' => ['max_redirects' => 100],
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
        ]);

        self::assertSame('seo-optimizer', $manifest->slug);
        self::assertSame('SEO Optimizer', $manifest->displayName);
        self::assertSame('2.1.0', $manifest->version);
        self::assertSame('Optimizes content for search engines', $manifest->description);
        self::assertSame('Acme Corp', $manifest->authorName);
        self::assertSame('https://acme.com', $manifest->authorUrl);
        self::assertSame('MIT', $manifest->license);
        self::assertSame('>=1.0.0', $manifest->pulsarVersionConstraint);
        self::assertSame(['hooks', 'admin_pages'], $manifest->capabilities);
        self::assertSame(['analytics' => '^1.0'], $manifest->dependencies);
        self::assertSame('Acme\\SeoPlugin', $manifest->entryPoint);
        self::assertSame(['max_redirects' => 100], $manifest->settings);
        self::assertSame(['psr-4' => ['Acme\\' => 'src/']], $manifest->autoload);
    }

    #[Test]
    public function fromArray_with_minimal_data(): void
    {
        $manifest = PluginManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->authorName);
        self::assertNull($manifest->authorUrl);
        self::assertNull($manifest->license);
        self::assertNull($manifest->pulsarVersionConstraint);
        self::assertSame([], $manifest->capabilities);
        self::assertSame([], $manifest->dependencies);
        self::assertNull($manifest->entryPoint);
        self::assertSame([], $manifest->settings);
        self::assertNull($manifest->autoload);
    }

    #[Test]
    public function fromArray_falls_back_to_name_key(): void
    {
        $manifest = PluginManifest::fromArray([
            'name' => 'Fallback Name',
        ]);

        self::assertSame('Fallback Name', $manifest->displayName);
    }

    #[Test]
    public function fromArray_prefers_display_name_over_name(): void
    {
        $manifest = PluginManifest::fromArray([
            'display_name' => 'Preferred',
            'name' => 'Fallback',
        ]);

        self::assertSame('Preferred', $manifest->displayName);
    }

    #[Test]
    public function fromArray_handles_non_string_capabilities(): void
    {
        $manifest = PluginManifest::fromArray([
            'capabilities' => [123, true, 'hooks'],
        ]);

        self::assertSame(['123', '1', 'hooks'], $manifest->capabilities);
    }

    #[Test]
    public function fromArray_handles_non_array_capabilities(): void
    {
        $manifest = PluginManifest::fromArray([
            'capabilities' => 'not-an-array',
        ]);

        self::assertSame([], $manifest->capabilities);
    }

    #[Test]
    public function fromArray_handles_non_array_autoload(): void
    {
        $manifest = PluginManifest::fromArray([
            'autoload' => 'invalid',
        ]);

        self::assertSame([], $manifest->autoload);
    }

    #[Test]
    public function fromArray_handles_non_array_dependencies(): void
    {
        $manifest = PluginManifest::fromArray([
            'dependencies' => 'not-an-array',
        ]);

        self::assertSame([], $manifest->dependencies);
    }
}
