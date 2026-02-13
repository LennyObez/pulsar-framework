<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Newsletter;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\FeedGeneratorServiceInterface;

use function date;
use function htmlspecialchars;
use function implode;
use function ltrim;
use function sprintf;

use const ENT_XML1;

/**
 * Generates RSS 2.0 and Atom 1.0 feeds for published content filtered by content type.
 *
 * Supports multilingual content with hreflang alternate links in feeds.
 */
#[Internal(reason: 'Use FeedGeneratorServiceInterface for public API')]
final readonly class FeedGeneratorService implements FeedGeneratorServiceInterface
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private CmsConfig $config,
        private string $baseUrl = 'https://example.com',
    ) {}

    public function generate(
        string $contentType,
        string $locale,
        string $format,
        int $limit = 20,
    ): string {
        return match ($format) {
            'atom' => $this->generateAtom($contentType, $locale, $limit),
            default => $this->generateRss($contentType, $locale, $limit),
        };
    }

    private function generateRss(string $contentType, string $locale, int $limit): string
    {
        $baseUrl = $this->resolveBaseUrl();

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            contentType: $contentType,
            page: 1,
            perPage: $limit,
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

            // Add hreflang alternate links for multilingual
            foreach ($this->config->supportedLocales as $altLocale) {
                if ($altLocale === $locale) {
                    continue;
                }

                $altTranslation = $this->translationRepository->findByContentAndLocale($content->id, $altLocale);

                if ($altTranslation !== null) {
                    $altLink = sprintf('%s/%s', $baseUrl, ltrim($altTranslation->path, '/'));
                    $itemLines[] = sprintf(
                        '      <atom:link rel="alternate" hreflang="%s" href="%s"/>',
                        $this->esc($altLocale),
                        $this->esc($altLink),
                    );
                }
            }

            $itemLines[] = '    </item>';
            $items[] = implode("\n", $itemLines);
        }

        $feedLink = sprintf('%s/feed/%s/rss', $baseUrl, $this->esc($contentType));
        $body = implode("\n", $items);
        $title = $this->esc($this->config->seo->titleSuffix !== '' ? $this->config->seo->titleSuffix : 'Feed');
        $escBaseUrl = $this->esc($baseUrl);
        $escLocale = $this->esc($locale);
        $escFeedLink = $this->esc($feedLink);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
              <channel>
                <title>$title</title>
                <link>$escBaseUrl</link>
                <description>Latest $contentType content</description>
                <language>$escLocale</language>
                <atom:link href="$escFeedLink" rel="self" type="application/rss+xml"/>
            $body
              </channel>
            </rss>
            XML;
    }

    private function generateAtom(string $contentType, string $locale, int $limit): string
    {
        $baseUrl = $this->resolveBaseUrl();

        $result = $this->contentRepository->findPublished(
            locale: $locale,
            contentType: $contentType,
            page: 1,
            perPage: $limit,
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

            // Hreflang alternate links
            foreach ($this->config->supportedLocales as $altLocale) {
                if ($altLocale === $locale) {
                    continue;
                }

                $altTranslation = $this->translationRepository->findByContentAndLocale($content->id, $altLocale);

                if ($altTranslation !== null) {
                    $altLink = sprintf('%s/%s', $baseUrl, ltrim($altTranslation->path, '/'));
                    $entryLines[] = sprintf(
                        '    <link rel="alternate" hreflang="%s" href="%s"/>',
                        $this->esc($altLocale),
                        $this->esc($altLink),
                    );
                }
            }

            $entryLines[] = '  </entry>';
            $entries[] = implode("\n", $entryLines);
        }

        $feedLink = sprintf('%s/feed/%s/atom', $baseUrl, $this->esc($contentType));
        $updatedStr = $latestUpdated !== null ? $latestUpdated->format('c') : date('c');
        $body = implode("\n", $entries);
        $title = $this->esc($this->config->seo->titleSuffix !== '' ? $this->config->seo->titleSuffix : 'Feed');
        $escBaseUrl = $this->esc($baseUrl);
        $escFeedLink = $this->esc($feedLink);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>$title</title>
              <link href="$escBaseUrl"/>
              <link href="$escFeedLink" rel="self"/>
              <id>$escBaseUrl/</id>
              <updated>$updatedStr</updated>
            $body
            </feed>
            XML;
    }

    private function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
