<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Plugins\Event\PluginDeleted;
use Pulsar\Extension\Cms\Plugins\Event\PluginDisabled;
use Pulsar\Extension\Cms\Plugins\Event\PluginEnabled;
use Pulsar\Extension\Cms\Plugins\Event\PluginInstalled;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\PluginCapability;
use Pulsar\Extension\Cms\Plugins\PluginManifest;

#[CoversClass(PluginDeleted::class)]
#[CoversClass(PluginDisabled::class)]
#[CoversClass(PluginEnabled::class)]
#[CoversClass(PluginInstalled::class)]
#[CoversClass(HookRegistry::class)]
#[CoversClass(PluginManifest::class)]
final class PluginEntitiesTest extends TestCase
{
    // -- Plugin Events --------------------------------------------------------

    #[Test]
    public function pluginInstalledEvent(): void
    {
        $event = new PluginInstalled(
            pluginId: 'plugin-01',
            name: 'SEO Optimizer',
            version: '1.2.0',
            installedBy: 'user-admin',
        );

        self::assertSame('plugin-01', $event->pluginId);
        self::assertSame('SEO Optimizer', $event->name);
        self::assertSame('1.2.0', $event->version);
        self::assertSame('user-admin', $event->installedBy);
    }

    #[Test]
    public function pluginEnabledEvent(): void
    {
        $event = new PluginEnabled(
            pluginId: 'plugin-01',
            enabledBy: 'user-admin',
        );

        self::assertSame('plugin-01', $event->pluginId);
        self::assertSame('user-admin', $event->enabledBy);
    }

    #[Test]
    public function pluginDisabledEvent(): void
    {
        $event = new PluginDisabled(
            pluginId: 'plugin-01',
            disabledBy: 'user-admin',
        );

        self::assertSame('plugin-01', $event->pluginId);
        self::assertSame('user-admin', $event->disabledBy);
    }

    #[Test]
    public function pluginDeletedEvent(): void
    {
        $event = new PluginDeleted(
            pluginId: 'plugin-01',
            deletedBy: 'user-admin',
            reason: 'No longer needed',
        );

        self::assertSame('plugin-01', $event->pluginId);
        self::assertSame('user-admin', $event->deletedBy);
        self::assertSame('No longer needed', $event->reason);
    }

    // -- PluginCapability -----------------------------------------------------

    #[Test]
    public function pluginCapabilityValues(): void
    {
        self::assertSame('content_types', PluginCapability::ContentTypes->value);
        self::assertSame('admin_pages', PluginCapability::AdminPages->value);
        self::assertSame('hooks', PluginCapability::Hooks->value);
        self::assertSame('shortcodes', PluginCapability::Shortcodes->value);
        self::assertSame('block_types', PluginCapability::BlockTypes->value);
    }

    // -- HookRegistry ---------------------------------------------------------

    #[Test]
    public function hookRegistryRegisterAndRetrieve(): void
    {
        $registry = new HookRegistry();

        $registry->register('before_render', static fn(): string => 'result', 10, 'seo-plugin');

        self::assertTrue($registry->has('before_render'));
        self::assertFalse($registry->has('after_render'));

        $callbacks = $registry->getCallbacks('before_render');
        self::assertCount(1, $callbacks);
        self::assertSame(10, $callbacks[0]['priority']);
        self::assertSame('seo-plugin', $callbacks[0]['pluginSlug']);
    }

    #[Test]
    public function hookRegistryReturnsSortedByPriority(): void
    {
        $registry = new HookRegistry();

        $registry->register('save', static fn(): null => null, 20, 'plugin-b');
        $registry->register('save', static fn(): null => null, 5, 'plugin-a');
        $registry->register('save', static fn(): null => null, 10, 'plugin-c');

        $callbacks = $registry->getCallbacks('save');
        self::assertSame('plugin-a', $callbacks[0]['pluginSlug']);
        self::assertSame('plugin-c', $callbacks[1]['pluginSlug']);
        self::assertSame('plugin-b', $callbacks[2]['pluginSlug']);
    }

    #[Test]
    public function hookRegistryReturnsEmptyForUnknownHookPoint(): void
    {
        $registry = new HookRegistry();

        self::assertSame([], $registry->getCallbacks('nonexistent'));
    }

    #[Test]
    public function hookRegistryGetHookPoints(): void
    {
        $registry = new HookRegistry();

        $registry->register('before_save', static fn(): null => null, 10, 'p1');
        $registry->register('after_save', static fn(): null => null, 10, 'p2');

        $points = $registry->getHookPoints();
        self::assertContains('before_save', $points);
        self::assertContains('after_save', $points);
    }

    // -- PluginManifest -------------------------------------------------------

    #[Test]
    public function pluginManifestConstructor(): void
    {
        $manifest = new PluginManifest(
            slug: 'analytics-pro',
            displayName: 'Analytics Pro',
            version: '2.0.0',
            description: 'Advanced analytics for CMS',
            authorName: 'Pulsar Labs',
            license: 'MIT',
            capabilities: ['hooks', 'admin_pages'],
            entryPoint: 'AnalyticsProPlugin',
        );

        self::assertSame('analytics-pro', $manifest->slug);
        self::assertSame('Analytics Pro', $manifest->displayName);
        self::assertSame('MIT', $manifest->license);
        self::assertCount(2, $manifest->capabilities);
        self::assertSame('AnalyticsProPlugin', $manifest->entryPoint);
    }

    #[Test]
    public function pluginManifestFromArrayWithFullData(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'contact-forms',
            'display_name' => 'Contact Forms',
            'version' => '1.5.0',
            'description' => 'Drag-and-drop form builder',
            'author_name' => 'FormTech',
            'author_url' => 'https://formtech.io',
            'license' => 'Apache-2.0',
            'pulsar_version' => '>=1.0.0',
            'capabilities' => ['shortcodes', 'block_types'],
            'dependencies' => ['mailer' => '>=1.0.0'],
            'entry_point' => 'ContactFormsPlugin',
            'settings' => ['recaptchaKey' => ''],
            'autoload' => ['psr-4' => ['ContactForms\\' => 'src/']],
        ]);

        self::assertSame('contact-forms', $manifest->slug);
        self::assertSame('Contact Forms', $manifest->displayName);
        self::assertSame('Apache-2.0', $manifest->license);
        self::assertSame('>=1.0.0', $manifest->pulsarVersionConstraint);
        self::assertSame(['mailer' => '>=1.0.0'], $manifest->dependencies);
        self::assertNotNull($manifest->autoload);
    }

    #[Test]
    public function pluginManifestFromArrayFallsBackToName(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'simple',
            'name' => 'Simple Plugin',
            'version' => '1.0.0',
        ]);

        self::assertSame('Simple Plugin', $manifest->displayName);
    }

    #[Test]
    public function pluginManifestFromArrayWithEmptyData(): void
    {
        $manifest = PluginManifest::fromArray([]);

        self::assertSame('', $manifest->slug);
        self::assertSame('', $manifest->displayName);
        self::assertSame('0.0.0', $manifest->version);
        self::assertNull($manifest->description);
        self::assertNull($manifest->entryPoint);
        self::assertNull($manifest->autoload);
        self::assertSame([], $manifest->capabilities);
    }
}
