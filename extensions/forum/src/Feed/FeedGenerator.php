<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Feed;

use DateTimeInterface;
use Pulsar\Api\Api;

use function htmlspecialchars;

use const ENT_XML1;

/**
 * RSS/Atom feed generator for forum threads and categories.
 *
 * Generates valid RSS 2.0 feeds from forum content, enabling
 * users to subscribe to new threads or posts via feed readers.
 */
#[Api(since: '1.0.0')]
final readonly class FeedGenerator
{
    public function __construct(
        private string $siteUrl,
        private string $siteTitle = 'Forum',
    ) {}

    /**
     * Generate an RSS 2.0 feed from a list of feed items.
     *
     * @param list<FeedItem> $items
     */
    public function generateRss(string $title, string $description, array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '  <title>' . $this->escape($title) . '</title>' . "\n";
        $xml .= '  <link>' . $this->escape($this->siteUrl) . '</link>' . "\n";
        $xml .= '  <description>' . $this->escape($description) . '</description>' . "\n";
        $xml .= '  <generator>Pulsar ' . $this->escape($this->siteTitle) . '</generator>' . "\n";

        foreach ($items as $item) {
            $xml .= '  <item>' . "\n";
            $xml .= '    <title>' . $this->escape($item->title) . '</title>' . "\n";
            $xml .= '    <link>' . $this->escape($item->url) . '</link>' . "\n";
            $xml .= '    <description>' . $this->escape($item->description) . '</description>' . "\n";
            $xml .= '    <guid isPermaLink="true">' . $this->escape($item->url) . '</guid>' . "\n";
            $xml .= '    <pubDate>' . $item->publishedAt->format(DateTimeInterface::RSS) . '</pubDate>' . "\n";

            if ($item->author !== '') {
                $xml .= '    <author>' . $this->escape($item->author) . '</author>' . "\n";
            }

            foreach ($item->categories as $category) {
                $xml .= '    <category>' . $this->escape($category) . '</category>' . "\n";
            }

            $xml .= '  </item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
