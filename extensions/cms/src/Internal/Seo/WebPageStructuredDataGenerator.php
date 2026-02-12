<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Seo;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Cms\Seo\StructuredDataGeneratorInterface;

use function ltrim;
use function rtrim;
use function sprintf;

/**
 * Generates WebPage schema.org structured data for page content type.
 */
#[Internal(reason: 'Use StructuredDataGeneratorInterface for public API')]
final readonly class WebPageStructuredDataGenerator implements StructuredDataGeneratorInterface
{
    public function supports(Content $content): bool
    {
        return $content->contentType === ContentType::Page;
    }

    public function generate(Content $content, ContentTranslation $translation, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $url = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $translation->metaTitle ?? $translation->title,
            'url' => $url,
            'inLanguage' => $translation->locale,
            'dateModified' => $content->updatedAt->format('c'),
        ];

        if ($content->publishedAt !== null) {
            $data['datePublished'] = $content->publishedAt->format('c');
        }

        if ($translation->metaDescription !== null) {
            $data['description'] = $translation->metaDescription;
        }

        return $data;
    }
}
