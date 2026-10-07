<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Seo\JsonLdCollection;
use Pulsar\Extension\Cms\Seo\MetaTagCollection;
use Pulsar\Extension\Cms\Seo\SeoServiceInterface;
use Pulsar\Extension\Cms\Seo\StructuredDataGeneratorInterface;

use function rtrim;
use function sprintf;
use function trim;

/**
 * Core SEO service generating meta tags and structured data for content items.
 *
 * @psalm-api Bound to SeoServiceInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use SeoServiceInterface for public API')]
final readonly class SeoService implements SeoServiceInterface
{
    /**
     * @param list<StructuredDataGeneratorInterface> $structuredDataGenerators
     */
    public function __construct(
        private CmsConfig $config,
        private ContentTranslationRepositoryInterface $translationRepository,
        private array $structuredDataGenerators = [],
    ) {}

    public function generateMetaTags(Content $content, ContentTranslation $translation, string $baseUrl): MetaTagCollection
    {
        $baseUrl = rtrim($baseUrl, '/');
        $seoConfig = $this->config->seo;

        $title = $this->buildTitle($translation, $seoConfig->titleSuffix);
        $description = $translation->metaDescription;
        $canonical = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));
        $robots = $translation->robots ?? $seoConfig->defaultRobots;

        $ogTags = [
            'og:title' => $title,
            'og:type' => match ($content->contentType->value) {
                'article' => 'article',
                default => 'website',
            },
            'og:url' => $canonical,
        ];

        if ($description !== null) {
            $ogTags['og:description'] = $description;
        }

        $twitterCards = [
            'twitter:card' => 'summary',
            'twitter:title' => $title,
        ];

        if ($description !== null) {
            $twitterCards['twitter:description'] = $description;
        }

        $hreflangLinks = $this->buildHreflangLinks($content, $baseUrl);

        return new MetaTagCollection(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $robots,
            ogTags: $ogTags,
            twitterCards: $twitterCards,
            hreflangLinks: $hreflangLinks,
        );
    }

    public function generateStructuredData(Content $content, ContentTranslation $translation, string $baseUrl): JsonLdCollection
    {
        if (!$this->config->seo->enableStructuredData) {
            return new JsonLdCollection();
        }

        $items = [];

        foreach ($this->structuredDataGenerators as $generator) {
            if ($generator->supports($content)) {
                $items[] = $generator->generate($content, $translation, $baseUrl);
            }
        }

        // Apply per-page overrides if configured
        if ($translation->structuredDataOverrides !== null) {
            $items[] = $translation->structuredDataOverrides;
        }

        return new JsonLdCollection($items);
    }

    public function generateBreadcrumbJsonLd(array $breadcrumbs): JsonLdCollection
    {
        if ($breadcrumbs === []) {
            return new JsonLdCollection();
        }

        $itemListElements = [];
        $position = 1;

        foreach ($breadcrumbs as $crumb) {
            $itemListElements[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
            $position++;
        }

        return new JsonLdCollection([
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $itemListElements,
            ],
        ]);
    }

    private function buildTitle(ContentTranslation $translation, string $suffix): string
    {
        $title = $translation->metaTitle ?? $translation->title;

        if ($suffix !== '') {
            $title = trim($title) . ' ' . $suffix;
        }

        return $title;
    }

    /**
     * Build hreflang links for all available translations of a content item.
     *
     * @return array<string, string>
     */
    private function buildHreflangLinks(Content $content, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $translations = $this->translationRepository->findByContentId($content->id);
        $links = [];

        foreach ($translations as $t) {
            $links[$t->locale] = sprintf('%s/%s', $baseUrl, ltrim($t->path, '/'));
        }

        // Add x-default pointing to the default locale translation
        if (isset($links[$this->config->defaultLocale])) {
            $links['x-default'] = $links[$this->config->defaultLocale];
        }

        return $links;
    }
}
