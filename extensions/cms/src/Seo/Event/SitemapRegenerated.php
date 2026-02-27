<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when the sitemap has been regenerated.
 *
 * @psalm-api Event constructed by SitemapGenerator and dispatched through
 *            the EventDispatcher.
 */
#[Api(since: '1.0.0')]
final readonly class SitemapRegenerated
{
    public function __construct(
        public int $pageCount,
        public int $localeCount,
    ) {}
}
