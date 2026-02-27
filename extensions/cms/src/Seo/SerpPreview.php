<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;

use function mb_strlen;
use function mb_substr;
use function preg_replace;

/**
 * Search engine results preview snippet (title, URL, meta description).
 *
 * @psalm-api Public DTO produced from content metadata; consumed by SEO
 *            preview admin panels.
 */
#[Api(since: '1.0.0')]
final readonly class SerpPreview
{
    private function __construct(
        public string $title,
        public string $metaDescription,
        public string $url,
        public string $displayTitle,
        public string $displayDescription,
        public string $displayUrl,
    ) {}

    public static function create(string $title, string $metaDescription, string $url): self
    {
        $displayTitle = mb_strlen($title) > 60
            ? mb_substr($title, 0, 57) . '...'
            : $title;

        $displayDescription = mb_strlen($metaDescription) > 160
            ? mb_substr($metaDescription, 0, 157) . '...'
            : $metaDescription;

        // Strip protocol for display
        $displayUrl = (string) preg_replace('#^https?://#', '', $url);

        return new self(
            title: $title,
            metaDescription: $metaDescription,
            url: $url,
            displayTitle: $displayTitle,
            displayDescription: $displayDescription,
            displayUrl: $displayUrl,
        );
    }

    /**
     * @return array{title: string, description: string, url: string, display_title: string, display_description: string, display_url: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->metaDescription,
            'url' => $this->url,
            'display_title' => $this->displayTitle,
            'display_description' => $this->displayDescription,
            'display_url' => $this->displayUrl,
        ];
    }
}
