<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;

use function htmlspecialchars;
use function implode;
use function ltrim;
use function rtrim;
use function sprintf;

use const ENT_XML1;

/**
 * Generates RSS 2.0 and Atom 1.0 feeds for published content.
 */
#[Internal(reason: 'Use FeedGeneratorInterface for public API')]
final readonly class FeedGenerator implements FeedGeneratorInterface
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private CmsConfig $config,
    ) {}

    public function generateRss(string $locale, string $baseUrl, int $limit = 20, ?string $tenantId = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            page: 1,
            perPage: $limit,
            tenantId: $tenantId,
        );

        $items = [];

        /** @var Content $content */
        foreach ($result->items as $content) {
            $translation = $this->translationRepository->findByContentAndLocale($content->id, $locale);

            if ($translation === null) {
                continue;
            }

            $link = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));

            $itemLines = [
                '    <item>',
                sprintf('      <title>%s</title>', $this->esc($translation->title)),
                sprintf('      <link>%s</link>', $this->esc($link)),
                sprintf('      <guid isPermaLink="true">%s</guid>', $this->esc($link)),
            ];

            if ($translation->excerpt !== null) {
                $itemLines[] = sprintf('      <description>%s</description>', $this->esc($translation->excerpt));
            } elseif ($translation->metaDescription !== null) {
                $itemLines[] = sprintf('      <description>%s</description>', $this->esc($translation->metaDescription));
            }

            $pubDate = $content->publishedAt ?? $content->createdAt;
            $itemLines[] = sprintf('      <pubDate>%s</pubDate>', $pubDate->format('r'));
            $itemLines[] = '    </item>';

            $items[] = implode("\n", $itemLines);
        }

        $feedLink = sprintf('%s/feed.xml', $baseUrl);
        $body = implode("\n", $items);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
              <channel>
                <title>{$this->esc($this->config->seo->titleSuffix ?: 'Feed')}</title>
                <link>{$this->esc($baseUrl)}</link>
                <description>Latest content</description>
                <language>{$this->esc($locale)}</language>
                <atom:link href="{$this->esc($feedLink)}" rel="self" type="application/rss+xml"/>
            {$body}
              </channel>
            </rss>
            XML;
    }

    public function generateAtom(string $locale, string $baseUrl, int $limit = 20, ?string $tenantId = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            page: 1,
            perPage: $limit,
            tenantId: $tenantId,
        );

        $entries = [];
        $latestUpdated = null;

        /** @var Content $content */
        foreach ($result->items as $content) {
            $translation = $this->translationRepository->findByContentAndLocale($content->id, $locale);

            if ($translation === null) {
                continue;
            }

            $link = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));
            $updated = $content->updatedAt->format('c');

            if ($latestUpdated === null || $content->updatedAt > $latestUpdated) {
                $latestUpdated = $content->updatedAt;
            }

            $entryLines = [
                '  <entry>',
                sprintf('    <title>%s</title>', $this->esc($translation->title)),
                sprintf('    <link href="%s"/>', $this->esc($link)),
                sprintf('    <id>%s</id>', $this->esc($link)),
                sprintf('    <updated>%s</updated>', $updated),
            ];

            if ($content->publishedAt !== null) {
                $entryLines[] = sprintf('    <published>%s</published>', $content->publishedAt->format('c'));
            }

            if ($translation->excerpt !== null) {
                $entryLines[] = sprintf('    <summary>%s</summary>', $this->esc($translation->excerpt));
            } elseif ($translation->metaDescription !== null) {
                $entryLines[] = sprintf('    <summary>%s</summary>', $this->esc($translation->metaDescription));
            }

            $entryLines[] = '  </entry>';
            $entries[] = implode("\n", $entryLines);
        }

        $feedLink = sprintf('%s/feed.atom', $baseUrl);
        $updatedStr = $latestUpdated !== null ? $latestUpdated->format('c') : date('c');
        $body = implode("\n", $entries);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>{$this->esc($this->config->seo->titleSuffix ?: 'Feed')}</title>
              <link href="{$this->esc($baseUrl)}"/>
              <link href="{$this->esc($feedLink)}" rel="self"/>
              <id>{$this->esc($baseUrl)}/</id>
              <updated>{$updatedStr}</updated>
            {$body}
            </feed>
            XML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
