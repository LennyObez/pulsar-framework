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
 * Generates Article schema.org structured data for article content type.
 *
 * @psalm-api Aggregated by SeoService through the StructuredDataGeneratorInterface
 *            contract; resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use StructuredDataGeneratorInterface for public API')]
final readonly class ArticleStructuredDataGenerator implements StructuredDataGeneratorInterface
{
    public function supports(Content $content): bool
    {
        return $content->contentType === ContentType::Article;
    }

    public function generate(Content $content, ContentTranslation $translation, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $url = sprintf('%s/%s', $baseUrl, ltrim($translation->path, '/'));

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $translation->metaTitle ?? $translation->title,
            'url' => $url,
            'inLanguage' => $translation->locale,
            'dateCreated' => $content->createdAt->format('c'),
            'dateModified' => $content->updatedAt->format('c'),
        ];

        if ($content->publishedAt !== null) {
            $data['datePublished'] = $content->publishedAt->format('c');
        }

        if ($translation->metaDescription !== null) {
            $data['description'] = $translation->metaDescription;
        }

        if ($translation->readingTimeMinutes !== null) {
            $data['timeRequired'] = sprintf('PT%dM', $translation->readingTimeMinutes);
        }

        return $data;
    }
}
