<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;

#[CoversClass(InstalledCmsPlugin::class)]
final class InstalledCmsPluginTest extends TestCase
{
    #[Test]
    public function enableSetsIsEnabledAndTimestamps(): void
    {
        $plugin = $this->createPlugin(isEnabled: false);
        $now = new DateTimeImmutable('2025-01-15 10:00:00');

        $enabled = $plugin->enable('admin-01', $now);

        self::assertTrue($enabled->isEnabled);
        self::assertSame('admin-01', $enabled->enabledBy);
        self::assertSame($now, $enabled->enabledAt);
        self::assertNull($enabled->disabledAt);
        // Original unchanged
        self::assertFalse($plugin->isEnabled);
    }

    #[Test]
    public function disableSetsIsDisabledAndTimestamp(): void
    {
        $plugin = $this->createPlugin(isEnabled: true);
        $now = new DateTimeImmutable('2025-01-15 11:00:00');

        $disabled = $plugin->disable($now);

        self::assertFalse($disabled->isEnabled);
        self::assertSame($now, $disabled->disabledAt);
        // Original unchanged
        self::assertTrue($plugin->isEnabled);
    }

    #[Test]
    public function isDeletedReturnsFalseWhenDeletedAtIsNull(): void
    {
        $plugin = $this->createPlugin();

        self::assertFalse($plugin->isDeleted());
    }

    #[Test]
    public function isDeletedReturnsTrueWhenDeletedAtIsSet(): void
    {
        $now = new DateTimeImmutable();
        $plugin = new InstalledCmsPlugin(
            id: 'p1',
            tenantId: null,
            slug: 'plugin-p1',
            displayName: 'Plugin P1',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc',
            packageHash: 'def',
            provenanceVerified: true,
            signatureVerified: false,
            capabilities: [],
            bootOrder: 0,
            isEnabled: false,
            storagePath: '/tmp/p1',
            installedAt: $now,
            installedBy: 'admin',
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            deletedAt: $now,
        );

        self::assertTrue($plugin->isDeleted());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();
        $plugin = new InstalledCmsPlugin(
            id: 'p1',
            tenantId: 'tenant-01',
            slug: 'my-plugin',
            displayName: 'My Plugin',
            version: '2.0.0',
            description: 'A test plugin',
            authorName: 'Author',
            authorUrl: 'https://example.com',
            license: 'MIT',
            manifestHash: 'hash1',
            packageHash: 'hash2',
            provenanceVerified: true,
            signatureVerified: true,
            capabilities: ['read', 'write'],
            bootOrder: 5,
            isEnabled: true,
            storagePath: '/plugins/my-plugin',
            installedAt: $now,
            installedBy: 'admin-01',
            enabledAt: $now,
            enabledBy: 'admin-01',
            disabledAt: null,
            deletedAt: null,
        );

        self::assertSame('p1', $plugin->id);
        self::assertSame('tenant-01', $plugin->tenantId);
        self::assertSame('my-plugin', $plugin->slug);
        self::assertSame('My Plugin', $plugin->displayName);
        self::assertSame('2.0.0', $plugin->version);
        self::assertSame('A test plugin', $plugin->description);
        self::assertSame('Author', $plugin->authorName);
        self::assertSame('https://example.com', $plugin->authorUrl);
        self::assertSame('MIT', $plugin->license);
        self::assertSame(['read', 'write'], $plugin->capabilities);
        self::assertSame(5, $plugin->bootOrder);
        self::assertTrue($plugin->isEnabled);
    }

    private function createPlugin(bool $isEnabled = false): InstalledCmsPlugin
    {
        $now = new DateTimeImmutable();

        return new InstalledCmsPlugin(
            id: 'p1',
            tenantId: null,
            slug: 'plugin-p1',
            displayName: 'Plugin P1',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc',
            packageHash: 'def',
            provenanceVerified: true,
            signatureVerified: false,
            capabilities: [],
            bootOrder: 0,
            isEnabled: $isEnabled,
            storagePath: '/tmp/p1',
            installedAt: $now,
            installedBy: 'admin',
            enabledAt: $isEnabled ? $now : null,
            enabledBy: $isEnabled ? 'admin' : null,
            disabledAt: null,
            deletedAt: null,
        );
    }
}
