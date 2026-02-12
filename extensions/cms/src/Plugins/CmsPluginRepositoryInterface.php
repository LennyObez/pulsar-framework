<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;

/**
 * Repository interface for the InstalledCmsPlugin entity.
 */
#[Api(since: '1.0.0')]
interface CmsPluginRepositoryInterface
{
    /**
     * Find an installed plugin by its ID.
     */
    public function findById(string $id): ?InstalledCmsPlugin;

    /**
     * Find an installed plugin by its slug, optionally scoped by tenant.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledCmsPlugin;

    /**
     * List all installed plugins, optionally scoped by tenant.
     *
     * @return list<InstalledCmsPlugin>
     */
    public function findAll(?string $tenantId = null): array;

    /**
     * List all enabled plugins, optionally scoped by tenant, ordered by boot_order.
     *
     * @return list<InstalledCmsPlugin>
     */
    public function findEnabled(?string $tenantId = null): array;

    /**
     * Persist an installed plugin.
     */
    public function save(InstalledCmsPlugin $plugin): void;

    /**
     * Soft-delete an installed plugin record.
     */
    public function delete(string $pluginId): void;
}
