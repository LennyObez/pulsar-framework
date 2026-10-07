<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Resolves editable design tokens from a theme's manifest.
 *
 * @psalm-api Public binding contract; implemented by ThemeTokenResolver and
 *            consumed by admin theme controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface ThemeTokenResolverInterface
{
    /**
     * Get all editable tokens declared by a theme.
     *
     * @return list<ThemeToken>
     */
    public function getEditableTokens(string $themeId): array;

    /**
     * Validate a value against a token's type and constraints.
     */
    public function validateTokenValue(ThemeToken $token, string $value): bool;
}
