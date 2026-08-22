<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Tools;

use Pulsar\Api\Internal;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Shared field resolution logic for import parsers.
 *
 * Normalizes field aliases (slug vs slug_segment, author vs author_id)
 * and provides consistent translation slug resolution used by both
 * SiteDefinitionParser and ImportParser.
 *
 * @psalm-api Mixed into SiteDefinitionParser and ImportParser; methods
 *            invoked through inheritance, never by external name lookup.
 */
#[Internal(reason: 'Import/export internals; shared helper trait')]
trait ImportFieldResolverTrait
{
    /**
     * Resolve the slug from import data, accepting both 'slug' and 'slug_segment'.
     *
     * Returns null only when neither field is present. An empty string is valid
     * (homepage / root page with empty slug).
     *
     * Falls back to $fallbackImportId when both slug fields are absent.
     *
     * @param array<string, mixed> $data
     */
    private function resolveSlug(array $data, ?string $fallbackImportId = null): ?string
    {
        if (is_string($data['slug'] ?? null)) {
            return $data['slug'];
        }

        if (is_string($data['slug_segment'] ?? null)) {
            return $data['slug_segment'];
        }

        if ($fallbackImportId !== null) {
            return $fallbackImportId;
        }

        return null;
    }

    /**
     * Resolve the author ID, accepting both 'author_id' and 'author' field names.
     *
     * Falls back to 'system' when neither field is present.
     *
     * @param array<string, mixed> $data
     */
    private function resolveAuthorId(array $data): string
    {
        if (is_string($data['author_id'] ?? null)) {
            return $data['author_id'];
        }

        if (is_string($data['author'] ?? null)) {
            return $data['author'];
        }

        return 'system';
    }

    /**
     * Resolve the slug segment for a single translation entry.
     *
     * Checks 'slug_segment' first, then 'slug', then falls back to
     * the root-level slug of the content item.
     *
     * @param array<string, mixed> $translationData Per-locale translation fields
     * @param string $rootSlug The content item root slug used as final fallback
     */
    private function resolveTranslationSlugSegment(array $translationData, string $rootSlug): string
    {
        if (is_string($translationData['slug_segment'] ?? null)) {
            return $translationData['slug_segment'];
        }

        if (is_string($translationData['slug'] ?? null)) {
            return $translationData['slug'];
        }

        return $rootSlug;
    }

    /**
     * Read a string field with default.
     *
     * @param array<string, mixed> $data
     */
    private static function asString(array $data, string $key, string $default = ''): string
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * Read a nullable string field.
     *
     * @param array<string, mixed> $data
     */
    private static function asNullableString(array $data, string $key): ?string
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read an integer field with default.
     *
     * @param array<string, mixed> $data
     */
    private static function asInt(array $data, string $key, int $default = 0): int
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * Read a boolean field with default.
     *
     * @param array<string, mixed> $data
     */
    private static function asBool(array $data, string $key, bool $default = false): bool
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }
}
