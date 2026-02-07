<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Repository interface for the InstalledTheme entity.
 */
#[Api(since: '1.0.0')]
interface ThemeRepositoryInterface
{
    /**
     * Find an installed theme by its ID.
     */
    public function findById(string $id): ?InstalledTheme;

    /**
     * Find an installed theme by its slug, optionally scoped by tenant.
     */
    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledTheme;

    /**
     * Find the currently active theme, optionally scoped by tenant.
     */
    public function findActive(?string $tenantId = null): ?InstalledTheme;

    /**
     * List all installed themes, optionally scoped by tenant.
     *
     * @return list<InstalledTheme>
     */
    public function findAll(?string $tenantId = null): array;

    /**
     * Persist an installed theme.
     */
    public function save(InstalledTheme $theme): void;

    /**
     * Delete an installed theme record.
     */
    public function delete(string $themeId): void;
}
