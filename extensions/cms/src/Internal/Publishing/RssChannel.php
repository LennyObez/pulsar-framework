<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Publishing;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\PublishingConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Publishing\PublishingChannelInterface;
use Pulsar\Extension\Cms\Publishing\PublishResult;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use Throwable;

/**
 * RSS/Atom feed publishing channel.
 *
 * On publish, regenerates the RSS feed file and writes it to the
 * configured public-accessible path via the media disk.
 */
#[Internal(reason: 'Use PublishingChannelInterface for public API')]
final readonly class RssChannel implements PublishingChannelInterface
{
    public function __construct(
        private FeedGeneratorInterface $feedGenerator,
        private MediaDiskInterface $disk,
        private PublishingConfig $config,
        private string $defaultLocale = 'en',
        private string $baseUrl = '',
    ) {}

    public function name(): string
    {
        return 'rss';
    }

    public function publish(Content $content, ContentTranslation $translation): PublishResult
    {
        return $this->regenerateFeed();
    }

    public function unpublish(Content $content): PublishResult
    {
        return $this->regenerateFeed();
    }

    public function isEnabled(): bool
    {
        return $this->config->rssEnabled;
    }

    private function regenerateFeed(): PublishResult
    {
        try {
            $rssXml = $this->feedGenerator->generateRss(
                $this->defaultLocale,
                $this->baseUrl,
            );

            $this->disk->write($this->config->rssFeedPath, $rssXml);

            return PublishResult::success($this->name(), $this->config->rssFeedPath);
        } catch (Throwable $e) {
            return PublishResult::failure($this->name(), $e->getMessage());
        }
    }
}
