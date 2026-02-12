<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Multi-channel publishing configuration.
 */
#[Api(since: '1.0.0')]
final readonly class PublishingConfig
{
    /**
     * @param bool $rssEnabled Whether the RSS feed channel is enabled
     * @param bool $staticSiteEnabled Whether the static site export channel is enabled
     * @param string $staticSiteOutputPath Directory for static HTML output
     * @param string $rssFeedPath Path for the generated RSS feed file
     */
    public function __construct(
        public bool $rssEnabled = false,
        public bool $staticSiteEnabled = false,
        public string $staticSiteOutputPath = './public/static',
        public string $rssFeedPath = './public/feed.xml',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rssEnabled: (bool) ($data['rss_enabled'] ?? false),
            staticSiteEnabled: (bool) ($data['static_site_enabled'] ?? false),
            staticSiteOutputPath: (string) ($data['static_site_output_path'] ?? './public/static'),
            rssFeedPath: (string) ($data['rss_feed_path'] ?? './public/feed.xml'),
        );
    }
}
