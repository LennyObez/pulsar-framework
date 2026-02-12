<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Newsletter;

use Pulsar\Api\Api;

/**
 * Generates syndication feeds (RSS 2.0, Atom 1.0) for published content.
 *
 * Differs from the Seo\FeedGeneratorInterface by supporting content type
 * filtering and format selection in a single method.
 */
#[Api(since: '1.0.0')]
interface FeedGeneratorServiceInterface
{
    /**
     * Generate a feed for the given content type.
     *
     * @param string $contentType Content type slug to filter by
     * @param string $locale Target locale
     * @param string $format 'rss' or 'atom'
     * @param int $limit Maximum number of entries
     *
     * @return string Generated XML feed
     */
    public function generate(
        string $contentType,
        string $locale,
        string $format,
        int $limit = 20,
    ): string;
}
