<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;

use function array_map;
use function ceil;
use function htmlspecialchars;
use function implode;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_contains;
use function strtolower;

use const ENT_XML1;

/**
 * Generates XML sitemaps with hreflang alternate links and pagination.
 *
 * @psalm-api Bound to SitemapGeneratorInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use SitemapGeneratorInterface for public API')]
final readonly class SitemapGenerator implements SitemapGeneratorInterface
{
    private const int MAX_URLS_PER_SITEMAP = 50_000;

    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private CmsConfig $config,
    ) {}

    public function generateIndex(string $baseUrl, ?string $tenantId = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $entries = [];

        foreach (ContentType::cases() as $contentType) {
            $result = $this->contentRepository->findPublished(
                locale: $this->config->defaultLocale,
                contentType: $contentType->value,
                page: 1,
                perPage: 1,
                tenantId: $tenantId,
            );

            $totalPages = (int) ceil(($result->total ?? 0) / self::MAX_URLS_PER_SITEMAP);
            $totalPages = $totalPages > 0 ? $totalPages : 1;

            for ($page = 1; $page <= $totalPages; $page++) {
                $loc = sprintf('%s/sitemap-%s-%d.xml', $baseUrl, $contentType->value, $page);
                $entries[] = sprintf('  <sitemap><loc>%s</loc></sitemap>', $this->escapeXml($loc));
            }
        }

        return $this->wrapSitemapIndex($entries);
    }

    public function generateForType(string $contentType, string $baseUrl, int $page = 1, ?string $tenantId = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $seoConfig = $this->config->seo;

        $result = $this->contentRepository->findPublished(
            locale: $this->config->defaultLocale,
            contentType: $contentType,
            page: $page,
            perPage: self::MAX_URLS_PER_SITEMAP,
            tenantId: $tenantId,
        );

        $changefreq = $seoConfig->sitemapChangefreq[$contentType] ?? 'weekly';
        $priority = $seoConfig->sitemapPriority[$contentType] ?? 0.5;
        $entries = [];

        // Batch-load all translations in a single query to avoid N+1
        $contentIds = array_map(static fn(Content $c): string => $c->id, $result->items);
        $translationsByContentId = $this->translationRepository->findByContentIds($contentIds);

        /** @var Content $content */
        foreach ($result->items as $content) {
            $translations = $translationsByContentId[$content->id] ?? [];

            if ($translations === []) {
                continue;
            }

            // Use the default locale translation as the primary URL
            $primaryTranslation = null;

            foreach ($translations as $t) {
                if ($t->locale === $this->config->defaultLocale) {
                    $primaryTranslation = $t;

                    break;
                }
            }

            $primaryTranslation ??= $translations[0];

            // Skip content marked as noindex
            if ($primaryTranslation->robots !== null
                && str_contains(strtolower($primaryTranslation->robots), 'noindex')
            ) {
                continue;
            }

            $loc = sprintf('%s/%s', $baseUrl, ltrim($primaryTranslation->path, '/'));
            $lastmod = $content->updatedAt->format('Y-m-d');

            $urlLines = [
                '  <url>',
                sprintf('    <loc>%s</loc>', $this->escapeXml($loc)),
                sprintf('    <lastmod>%s</lastmod>', $lastmod),
                sprintf('    <changefreq>%s</changefreq>', $changefreq),
                sprintf('    <priority>%.1f</priority>', $priority),
            ];

            // Add hreflang links for all translations
            foreach ($translations as $t) {
                $href = sprintf('%s/%s', $baseUrl, ltrim($t->path, '/'));
                $urlLines[] = sprintf(
                    '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                    $this->escapeXml($t->locale),
                    $this->escapeXml($href),
                );
            }

            // Add x-default pointing to the default locale
            if ($primaryTranslation->locale === $this->config->defaultLocale) {
                $urlLines[] = sprintf(
                    '    <xhtml:link rel="alternate" hreflang="x-default" href="%s"/>',
                    $this->escapeXml($loc),
                );
            }

            $urlLines[] = '  </url>';
            $entries[] = implode("\n", $urlLines);
        }

        return $this->wrapUrlset($entries);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }

    /**
     * @param list<string> $entries
     */
    private function wrapSitemapIndex(array $entries): string
    {
        $body = implode("\n", $entries);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            $body
            </sitemapindex>
            XML;
    }

    /**
     * @param list<string> $entries
     */
    private function wrapUrlset(array $entries): string
    {
        $body = implode("\n", $entries);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
                    xmlns:xhtml="http://www.w3.org/1999/xhtml">
            $body
            </urlset>
            XML;
    }
}
