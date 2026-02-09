<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Resolves alternate URLs for a given path across all supported locales.
 *
 * Implementations may use simple prefix-swapping or route-aware slug
 * translation depending on the application's URL scheme.
 */
#[Api(since: '1.0.0')]
interface LocaleUrlResolverInterface
{
    /**
     * Resolve alternate URLs for each supported locale.
     *
     * @return array<string, string> Locale tag => URL path
     */
    #[NoDiscard]
    public function resolveAlternates(string $currentPath, string $currentLocale): array;
}
