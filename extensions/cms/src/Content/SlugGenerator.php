<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

use function strlen;

/**
 * Generates, validates, and ensures uniqueness of URL slug segments.
 *
 * Slugs are single path segments (no slashes) used in content URLs.
 * Transliteration converts non-ASCII characters to ASCII equivalents.
 *
 * @psalm-api Resolved by content/translation services from the DI
 *            container; not new'd by name.
 */
#[Api(since: '1.0.0')]
final readonly class SlugGenerator
{
    private const int MAX_LENGTH = 200;

    /**
     * Generate a slug from a content title.
     *
     * Transliterates non-ASCII characters, lowercases, replaces non-alphanumeric
     * characters with hyphens, collapses consecutive hyphens, and trims hyphens.
     */
    public function generate(string $title): string
    {
        // Transliterate to ASCII using intl (cross-platform) with iconv fallback
        $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $title);

        if ($slug === false) {
            $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        }

        if ($slug === false) {
            $slug = $title;
        }

        // Lowercase
        $slug = strtolower($slug);

        // Replace non-alphanumeric with hyphens
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Collapse consecutive hyphens
        $slug = (string) preg_replace('/-{2,}/', '-', $slug);

        // Trim hyphens from ends
        $slug = trim($slug, '-');

        // Enforce max length
        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = substr($slug, 0, self::MAX_LENGTH);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    /**
     * Validate that a slug matches the required format.
     *
     * Must be lowercase alphanumeric with hyphens, start and end with
     * alphanumeric, no consecutive hyphens, max 200 characters.
     */
    public function validate(string $slug): bool
    {
        return ContentTranslation::isValidSlug($slug);
    }

    /**
     * Ensure a slug is unique within a locale and tenant scope.
     *
     * If the slug already exists, appends -2, -3, etc. until a unique variant is found.
     *
     * @param string $slug Base slug to check
     * @param string $locale BCP 47 locale code
     * @param string|null $tenantId Tenant scope, null for single-tenant
     * @param string|null $excludeContentId Content ID to exclude from conflict check (for updates)
     */
    public function ensureUnique(
        string $slug,
        string $locale,
        ?string $tenantId,
        ?string $excludeContentId,
        ConnectionInterface $db,
    ): string {
        $candidate = $slug;
        $suffix = 1;

        while ($this->slugExists($candidate, $locale, $tenantId, $excludeContentId, $db)) {
            $suffix++;
            $candidate = $slug . '-' . $suffix;

            // Ensure suffixed slug doesn't exceed max length
            if (strlen($candidate) > self::MAX_LENGTH) {
                $trimmedSlug = substr($slug, 0, self::MAX_LENGTH - strlen('-' . $suffix));
                $trimmedSlug = rtrim($trimmedSlug, '-');
                $candidate = $trimmedSlug . '-' . $suffix;
            }
        }

        return $candidate;
    }

    private function slugExists(
        string $slug,
        string $locale,
        ?string $tenantId,
        ?string $excludeContentId,
        ConnectionInterface $db,
    ): bool {
        $sql = <<<'SQL'
            SELECT 1 FROM cms_content_translations ct
            JOIN cms_contents c ON c.id = ct.content_id
            WHERE ct.slug_segment = :slug
              AND ct.locale = :locale
            SQL;

        $bindings = [
            'slug' => $slug,
            'locale' => $locale,
        ];

        if ($tenantId !== null) {
            $sql .= ' AND c.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        } else {
            $sql .= ' AND c.tenant_id IS NULL';
        }

        if ($excludeContentId !== null) {
            $sql .= ' AND ct.content_id != :exclude_id';
            $bindings['exclude_id'] = $excludeContentId;
        }

        $sql .= ' LIMIT 1';

        return !$db->query($sql, $bindings)->isEmpty();
    }
}
