<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * High-level theme lifecycle manager.
 */
#[Api(since: '1.0.0')]
interface ThemeManagerInterface
{
    /**
     * Install a theme from an archive file path.
     *
     * @throws CmsException If installation fails (validation, provenance, extraction)
     */
    public function install(string $archivePath, string $installedBy, ?string $tenantId = null): InstalledTheme;

    /**
     * Activate an installed theme, deactivating the currently active theme.
     *
     * @throws CmsException If the theme is not found or already active
     */
    public function activate(string $themeId, string $activatedBy): InstalledTheme;

    /**
     * Deactivate the currently active theme.
     *
     * @throws CmsException If the theme is not found or not active
     */
    public function deactivate(string $themeId, string $deactivatedBy): InstalledTheme;

    /**
     * Soft-delete an installed theme.
     *
     * @throws CmsException If the theme is not found or is currently active
     */
    public function delete(string $themeId, string $deletedBy, string $reason): void;

    /**
     * Start a theme preview session for a specific theme.
     *
     * @throws CmsException If the theme is not found
     */
    public function preview(string $themeId, string $userId): PreviewSession;

    /**
     * Rollback to the previously active theme.
     *
     * @throws CmsException If no previous theme is available
     */
    public function rollback(string $rolledBackBy, ?string $tenantId = null): InstalledTheme;

    /**
     * Get the currently active theme, if any.
     */
    public function getActive(?string $tenantId = null): ?InstalledTheme;

    /**
     * List all installed themes.
     *
     * @return list<InstalledTheme>
     */
    public function getInstalled(?string $tenantId = null): array;
}
