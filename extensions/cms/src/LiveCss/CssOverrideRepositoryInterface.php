<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

/**
 * Repository for CSS override persistence.
 */
interface CssOverrideRepositoryInterface
{
    /**
     * Find the currently active override for a theme.
     */
    public function findActive(string $themeId, ?string $tenantId = null): ?CssOverride;

    /**
     * Find a specific version of an override for a theme.
     */
    public function findByVersion(string $themeId, ?string $tenantId, int $version): ?CssOverride;

    /**
     * Find an override by its ID.
     */
    public function findById(string $id): ?CssOverride;

    /**
     * Get paginated version history for a theme.
     *
     * @return list<CssOverride>
     */
    public function getHistory(string $themeId, ?string $tenantId, int $page, int $perPage): array;

    /**
     * Get the next version number for a theme.
     */
    public function getNextVersion(string $themeId, ?string $tenantId): int;

    /**
     * Persist an override.
     */
    public function save(CssOverride $override): void;

    /**
     * Deactivate all overrides for a theme and optional tenant.
     */
    public function deactivateAll(string $themeId, ?string $tenantId): void;
}
