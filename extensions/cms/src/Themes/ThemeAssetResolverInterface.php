<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Resolves theme assets and templates to filesystem paths.
 *
 * @psalm-api Public binding contract; implemented by ThemeAssetResolver and
 *            consumed by template rendering.
 */
#[Api(since: '1.0.0')]
interface ThemeAssetResolverInterface
{
    /**
     * Resolve a logical asset name to its public URL or filesystem path.
     *
     * @throws CmsException If the asset cannot be resolved
     */
    public function resolve(string $assetName, ?string $themeId = null): string;

    /**
     * Resolve a template name to its filesystem path within the active theme.
     *
     * @throws CmsException If the template cannot be resolved
     */
    public function resolveTemplate(string $templateName, ?string $themeId = null): string;

    /**
     * Verify the integrity of all deployed assets for a theme.
     */
    public function verifyIntegrity(string $themeId): ProvenanceResult;
}
