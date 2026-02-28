<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * High-level plugin lifecycle manager.
 *
 * @psalm-api Public binding contract; implemented by CmsPluginManager and
 *            consumed by admin plugin controllers.
 */
#[Api(since: '1.0.0')]
interface CmsPluginManagerInterface
{
    /**
     * Install a plugin from an archive file path.
     *
     * Extracts with Zip Slip protection, validates the manifest, verifies
     * provenance, and stores the plugin files and DB record.
     *
     * @throws CmsException If installation fails (validation, provenance, extraction)
     */
    public function install(string $archivePath, string $installedBy, ?string $tenantId = null): InstalledCmsPlugin;

    /**
     * Enable an installed plugin.
     *
     * @throws CmsException If the plugin is not found or already enabled
     */
    public function enable(string $pluginId, string $enabledBy): InstalledCmsPlugin;

    /**
     * Disable an enabled plugin.
     *
     * @throws CmsException If the plugin is not found or not enabled
     */
    public function disable(string $pluginId, string $disabledBy): InstalledCmsPlugin;

    /**
     * Soft-delete a disabled plugin, removing its files.
     *
     * @throws CmsException If the plugin is not found or is currently enabled
     */
    public function delete(string $pluginId, string $deletedBy, string $reason): void;

    /**
     * Boot all enabled plugins in dependency-resolved order.
     *
     * Creates plugin contexts, calls register() then boot() on each plugin.
     * Tracks failures per plugin and auto-disables after the circuit breaker threshold.
     */
    public function bootAll(?string $tenantId = null): void;

    /**
     * List all installed plugins.
     *
     * @return list<InstalledCmsPlugin>
     */
    public function getInstalled(?string $tenantId = null): array;
}
