<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\I18n;

use Pulsar\Api\Api;

/**
 * Value object representing a single hreflang alternate link.
 *
 * Rendered as `<link rel="alternate" hreflang="{locale}" href="{href}">` in the HTML head.
 */
#[Api(since: '1.0.0')]
final readonly class HreflangLink
{
    public function __construct(
        public string $locale,
        public string $href,
    ) {}
}
