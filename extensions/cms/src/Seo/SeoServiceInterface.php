<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;

/**
 * Core SEO service for generating meta tags and structured data.
 *
 * @psalm-api Public binding contract; implemented by SeoService and consumed
 *            by content templates.
 */
#[Api(since: '1.0.0')]
interface SeoServiceInterface
{
    /**
     * Generate meta tags for a content item in a specific locale.
     */
    public function generateMetaTags(Content $content, ContentTranslation $translation, string $baseUrl): MetaTagCollection;

    /**
     * Generate JSON-LD structured data for a content item.
     */
    public function generateStructuredData(Content $content, ContentTranslation $translation, string $baseUrl): JsonLdCollection;

    /**
     * Generate breadcrumb JSON-LD for a content item's hierarchy path.
     *
     * @param list<array{name: string, url: string}> $breadcrumbs
     */
    public function generateBreadcrumbJsonLd(array $breadcrumbs): JsonLdCollection;
}
