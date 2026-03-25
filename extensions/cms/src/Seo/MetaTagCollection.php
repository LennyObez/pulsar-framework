<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

/**
 * Immutable collection of SEO meta tags for a content page.
 *
 * @psalm-api Public DTO returned from SeoServiceInterface; consumed by
 *            content templates rendering the head section.
 */
#[Api(since: '1.0.0')]
final readonly class MetaTagCollection
{
    /**
     * @param string|null $title Page title (null = inherit from content)
     * @param string|null $description Meta description
     * @param string|null $canonical Canonical URL
     * @param string|null $robots Robots directive
     * @param array<string, string> $ogTags Open Graph key-value pairs
     * @param array<string, string> $twitterCards Twitter Card key-value pairs
     * @param array<string, string> $hreflangLinks Locale-to-URL hreflang mapping
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $robots = null,
        public array $ogTags = [],
        public array $twitterCards = [],
        public array $hreflangLinks = [],
    ) {}

    /**
     * Render all meta tags as an HTML string.
     */
    public function toHtml(): string
    {
        $lines = [];

        if ($this->title !== null) {
            $lines[] = '<title>' . htmlspecialchars($this->title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</title>';
        }

        if ($this->description !== null) {
            $lines[] = '<meta name="description" content="' . htmlspecialchars($this->description, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        if ($this->canonical !== null) {
            $lines[] = '<link rel="canonical" href="' . htmlspecialchars($this->canonical, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        if ($this->robots !== null) {
            $lines[] = '<meta name="robots" content="' . htmlspecialchars($this->robots, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        foreach ($this->ogTags as $property => $content) {
            $lines[] = '<meta property="' . htmlspecialchars($property, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" content="' . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        foreach ($this->twitterCards as $name => $content) {
            $lines[] = '<meta name="' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" content="' . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        foreach ($this->hreflangLinks as $locale => $href) {
            $lines[] = '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        return implode("\n", $lines);
    }
}
