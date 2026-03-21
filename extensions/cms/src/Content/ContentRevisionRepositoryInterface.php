<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use Pulsar\Api\Api;

/**
 * Repository interface for content revisions.
 *
 * @psalm-api Public binding contract; implemented by DbContentRevisionRepository
 *            and consumed by RevisionService and admin revision views.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentRevisionRepositoryInterface
{
    public function findById(string $id): ?ContentRevision;

    /**
     * Find all revisions for a content item in a locale, ordered by revision number descending.
     *
     * @return list<ContentRevision>
     */
    public function findByContentAndLocale(string $contentId, string $locale): array;

    /**
     * Get the latest revision number for a content+locale pair.
     */
    public function getLatestRevisionNumber(string $contentId, string $locale): int;

    public function save(ContentRevision $revision): void;
}
