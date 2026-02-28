<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Repository interface for content translations.
 *
 * @psalm-api Public binding contract; implemented by
 *            DbContentTranslationRepository and consumed by URL resolution,
 *            content services, and admin controllers.
 */
#[Api(since: '1.0.0')]
interface ContentTranslationRepositoryInterface
{
    public function findById(string $id): ?ContentTranslation;

    /**
     * Find all translations for a content item.
     *
     * @return list<ContentTranslation>
     */
    public function findByContentId(string $contentId): array;

    /**
     * Find translation for a specific content + locale combination.
     */
    public function findByContentAndLocale(string $contentId, string $locale): ?ContentTranslation;

    /**
     * Find translation by full path and locale.
     */
    public function findByPath(string $locale, string $path, ?string $tenantId = null): ?ContentTranslation;

    /**
     * Find all translations for multiple content items in a single query.
     *
     * @param list<string> $contentIds UUIDv7 content IDs
     * @return array<string, list<ContentTranslation>> Keyed by content ID
     */
    public function findByContentIds(array $contentIds): array;

    public function save(ContentTranslation $translation): void;

    public function delete(string $id): void;
}
