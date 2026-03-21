<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Service for managing live CSS overrides on installed themes.
 *
 * @psalm-api Public binding contract; implemented by LiveCssService and
 *            consumed by admin theme controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface LiveCssServiceInterface
{
    /**
     * Get the currently active CSS override for a theme.
     */
    public function getCurrentOverrides(string $themeId, ?string $tenantId = null): ?CssOverride;

    /**
     * Save new CSS overrides, creating a new version.
     *
     * @param array<string, string> $tokenOverrides
     *
     * @throws CmsException If CSS validation fails
     */
    public function saveOverrides(
        string $themeId,
        string $cssContent,
        array $tokenOverrides,
        string $reason,
        string $createdBy,
        ?string $tenantId = null,
    ): CssOverride;

    /**
     * Rollback to a previous override version by creating a new version
     * with the old content (never overwrites history).
     *
     * @throws CmsException If the override is not found
     */
    public function rollback(string $overrideId, string $reason, string $actorId): CssOverride;

    /**
     * Get paginated version history for a theme's CSS overrides.
     *
     * @return list<CssOverride>
     */
    public function getVersionHistory(
        string $themeId,
        ?string $tenantId,
        int $page = 1,
        int $perPage = 20,
    ): array;
}
