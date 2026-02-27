<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Generates RSS and Atom feeds for published content.
 *
 * @psalm-api Public binding contract; implemented by FeedGenerator and
 *            consumed by RssChannel + feed routes.
 */
#[Api(since: '1.0.0')]
interface FeedGeneratorInterface
{
    /**
     * Generate an RSS 2.0 feed for the given locale.
     */
    public function generateRss(string $locale, string $baseUrl, int $limit = 20, ?string $tenantId = null): string;

    /**
     * Generate an Atom 1.0 feed for the given locale.
     */
    public function generateAtom(string $locale, string $baseUrl, int $limit = 20, ?string $tenantId = null): string;
}
