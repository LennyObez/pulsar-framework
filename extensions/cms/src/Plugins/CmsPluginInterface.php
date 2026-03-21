<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

/**
 * Contract for CMS plugins.
 *
 * Plugins implement this interface and declare it as the entry_point in plugin.json.
 * The register() method is called once during installation; boot() is called on every
 * request where the plugin is enabled.
 *
 * @psalm-api Public extension contract; implementations are loaded by name from
 *            installed plugin packages by CmsPluginManager.
 * @api
 */
#[Api(since: '1.0.0')]
interface CmsPluginInterface
{
    /**
     * Human-readable plugin name.
     */
    public function name(): string;

    /**
     * Register plugin services and bindings.
     *
     * Called once during plugin installation/boot. Use the context to declare
     * content types, shortcodes, block types, and admin pages.
     */
    public function register(CmsPluginContext $context): void;

    /**
     * Boot the plugin after all plugins have been registered.
     *
     * Called on every request where the plugin is enabled. Use the context
     * to register hooks that depend on other plugins being registered.
     */
    public function boot(CmsPluginContext $context): void;

    /**
     * Capabilities this plugin provides.
     *
     * @return list<PluginCapability>
     */
    public function capabilities(): array;
}
