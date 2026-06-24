<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Pulsar\Api\Api;

/**
 * Result of resolving a localized URL path against the {@see SlugRegistry}.
 *
 * Produced when an incoming path's leading segments match either a locale's
 * canonical slug or a route key used as a cross-locale alias.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SlugMatch
{
    /**
     * @param string $key       The canonical route key (e.g. `development/projects`).
     * @param string $remainder Trailing path segments after the matched slug,
     *                          without leading/trailing slashes (e.g. `my-post`);
     *                          empty when the slug consumed the whole path.
     * @param bool   $isCanonical Whether the matched form is the canonical slug
     *                          for the locale. False when the request used the
     *                          route key (or another non-canonical alias) and a
     *                          301 to the canonical slug is warranted.
     */
    public function __construct(
        public string $key,
        public string $remainder,
        public bool $isCanonical,
    ) {}
}
