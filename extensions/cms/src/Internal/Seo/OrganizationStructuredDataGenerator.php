<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Seo\StructuredDataGeneratorInterface;

use function rtrim;

/**
 * Generates Organization schema.org structured data for the homepage.
 *
 * This generator only activates for the root page (path = '' or '/') so
 * the Organization schema appears once on the site, per Google's recommendation.
 *
 * @psalm-api Aggregated by SeoService through the StructuredDataGeneratorInterface
 *            contract; resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use StructuredDataGeneratorInterface for public API')]
final readonly class OrganizationStructuredDataGenerator implements StructuredDataGeneratorInterface
{
    public function __construct(
        private CmsConfig $config,
    ) {}

    public function supports(Content $content): bool
    {
        // Organization structured data is only for the homepage
        return $content->parentId === null && $content->sortOrder === 0;
    }

    public function generate(Content $content, ContentTranslation $translation, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $this->config->seo->titleSuffix !== ''
                ? $this->config->seo->titleSuffix
                : ($translation->metaTitle ?? $translation->title),
            'url' => $baseUrl,
        ];

        if ($translation->metaDescription !== null) {
            $data['description'] = $translation->metaDescription;
        }

        return $data;
    }
}
