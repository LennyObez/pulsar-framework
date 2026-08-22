<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;

#[CoversClass(InstalledCmsPlugin::class)]
final class InstalledCmsPluginTest extends TestCase
{
    private function createPlugin(bool $isEnabled = false): InstalledCmsPlugin
    {
        return new InstalledCmsPlugin(
            id: 'plugin-1',
            tenantId: null,
            slug: 'seo-plugin',
            displayName: 'SEO Plugin',
            version: '1.0.0',
            description: 'An SEO plugin',
            authorName: 'Author',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: true,
            signatureVerified: true,
            capabilities: ['hooks'],
            bootOrder: 1,
            isEnabled: $isEnabled,
            storagePath: 'plugins/seo-plugin',
            installedAt: new DateTimeImmutable(),
            installedBy: 'user-1',
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            deletedAt: null,
        );
    }

    #[Test]
    public function enable_sets_enabled_flag_and_timestamps(): void
    {
        $plugin = $this->createPlugin(isEnabled: false);
        $now = new DateTimeImmutable();
        $enabled = $plugin->enable('admin-1', $now);

        self::assertTrue($enabled->isEnabled);
        self::assertSame($now, $enabled->enabledAt);
        self::assertSame('admin-1', $enabled->enabledBy);
        self::assertNull($enabled->disabledAt);
    }

    #[Test]
    public function disable_clears_enabled_and_sets_disabledAt(): void
    {
        $plugin = $this->createPlugin(isEnabled: true);
        $now = new DateTimeImmutable();
        $disabled = $plugin->disable($now);

        self::assertFalse($disabled->isEnabled);
        self::assertSame($now, $disabled->disabledAt);
    }

    #[Test]
    public function isDeleted_returns_false_when_no_deletedAt(): void
    {
        $plugin = $this->createPlugin();

        self::assertFalse($plugin->isDeleted());
    }

    #[Test]
    public function isDeleted_returns_true_when_deletedAt_set(): void
    {
        $plugin = new InstalledCmsPlugin(
            id: 'p1',
            tenantId: null,
            slug: 's',
            displayName: 'D',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'h1',
            packageHash: 'h2',
            provenanceVerified: false,
            signatureVerified: false,
            capabilities: [],
            bootOrder: 0,
            isEnabled: false,
            storagePath: 'p',
            installedAt: new DateTimeImmutable(),
            installedBy: 'u1',
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            deletedAt: new DateTimeImmutable(),
        );

        self::assertTrue($plugin->isDeleted());
    }
}
