<?php

declare(strict_types=1);

namespace Pulsar\I18n\Locale;

use Pulsar\Api\Api;

/**
 * Value object representing an hreflang alternate link.
 *
 * Used by locale URL generation to produce `<link rel="alternate" hreflang="...">`
 * tags for search engine locale discovery.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HreflangLink
{
    public function __construct(
        public string $locale,
        public string $href,
    ) {}
}
