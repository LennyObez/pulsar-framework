<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Feed;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A single item in an RSS feed.
 */
#[Api(since: '1.0.0')]
final readonly class FeedItem
{
    /**
     * @param string $title Item title
     * @param string $url Permalink URL
     * @param string $description Item description (plain text or HTML)
     * @param string $author Author name or email
     * @param list<string> $categories Category/tag names
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
        public DateTimeImmutable $publishedAt,
        public string $author = '',
        public array $categories = [],
    ) {}
}
