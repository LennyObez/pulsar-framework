<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Exception\CmsException;

use function strlen;

/**
 * Per-locale content translation fields.
 *
 * Each content item has one translation per supported locale.
 * The slug_segment and path are used for URL resolution.
 *
 * @psalm-api Public DTO returned from ContentTranslationRepositoryInterface;
 *            consumed by content services, URL resolution, and template
 *            rendering.
 */
#[Api(since: '1.0.0')]
final readonly class ContentTranslation
{
    /**
     * Slug validation pattern: lowercase alphanumeric (including Unicode letters)
     * with hyphens and forward slashes for nested paths. Each segment must start
     * and end with an alphanumeric character, no consecutive hyphens or slashes.
     *
     * Examples of valid slugs:
     *   - "about-us"
     *   - "services/php-developer"
     *   - "developpeur-php" (Unicode accents)
     */
    public const string SLUG_PATTERN = '/^[\p{Ll}\p{N}](?:[\p{Ll}\p{N}\-\/]*[\p{Ll}\p{N}])?$/u';

    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param string $locale BCP 47 locale code
     * @param string $title Content title (plain text, max 500)
     * @param string $slugSegment Single URL segment, no slashes
     * @param string $path Computed full path (e.g., docs/getting-started)
     * @param string $body Safe HTML body content
     * @param string|null $excerpt Optional summary
     * @param string|null $metaTitle SEO title override (max 70)
     * @param string|null $metaDescription SEO description (max 170)
     * @param string|null $ogImageId UUIDv7 FK media_assets
     * @param string|null $robots Robots directive override
     * @param array<string, mixed>|null $structuredDataOverrides Per-page JSON-LD overrides
     * @param int|null $readingTimeMinutes Computed on save
     * @param string $bodyPlaintext HTML-stripped body text (tsvector input weight C)
     * @param string $headingsText Extracted h2-h6 text, newline-separated (tsvector input weight B)
     * @param string $customFieldsText Concatenated searchable custom field values (tsvector input weight B)
     * @param string $taxonomyTermsText Concatenated taxonomy term names (tsvector input weight D)
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $locale,
        public string $title,
        public string $slugSegment,
        public string $path,
        public string $body,
        public ?string $excerpt,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?string $ogImageId,
        public ?string $robots,
        public ?array $structuredDataOverrides,
        public ?int $readingTimeMinutes,
        public string $bodyPlaintext,
        public string $headingsText,
        public string $customFieldsText,
        public string $taxonomyTermsText,
    ) {}

    /**
     * Create a new content translation.
     *
     * @param array<string, mixed>|null $structuredDataOverrides
     * @throws CmsException If the slug format is invalid
     */
    public static function create(
        string $id,
        string $contentId,
        string $locale,
        string $title,
        string $slugSegment,
        string $path,
        string $body,
        ?string $excerpt = null,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?string $ogImageId = null,
        ?string $robots = null,
        ?array $structuredDataOverrides = null,
        ?int $readingTimeMinutes = null,
        string $bodyPlaintext = '',
        string $headingsText = '',
        string $customFieldsText = '',
        string $taxonomyTermsText = '',
    ): self {
        self::validateSlug($slugSegment);

        return new self(
            id: $id,
            contentId: $contentId,
            locale: $locale,
            title: $title,
            slugSegment: $slugSegment,
            path: $path,
            body: $body,
            excerpt: $excerpt,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            ogImageId: $ogImageId,
            robots: $robots,
            structuredDataOverrides: $structuredDataOverrides,
            readingTimeMinutes: $readingTimeMinutes,
            bodyPlaintext: $bodyPlaintext,
            headingsText: $headingsText,
            customFieldsText: $customFieldsText,
            taxonomyTermsText: $taxonomyTermsText,
        );
    }

    /**
     * Whether the given slug segment matches the required format.
     */
    public static function isValidSlug(string $slug): bool
    {
        // Empty slug is valid for the homepage (site root page)
        if ($slug === '') {
            return true;
        }

        return preg_match(self::SLUG_PATTERN, $slug) === 1
            && strlen($slug) <= 200
            && !str_contains($slug, '--')
            && !str_contains($slug, '//');
    }

    /**
     * @throws CmsException If the slug format is invalid
     */
    private static function validateSlug(string $slug): void
    {
        if (!self::isValidSlug($slug)) {
            throw CmsException::invalidSlug($slug);
        }
    }
}
