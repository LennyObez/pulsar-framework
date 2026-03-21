<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Service interface for managing content-taxonomy term associations.
 *
 * @psalm-api Public binding contract; implemented by TaxonomyService and
 *            consumed by content services and admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface TaxonomyServiceInterface
{
    /**
     * Attach taxonomy terms to a content item.
     *
     * @param list<string> $termIds UUIDv7 term identifiers
     */
    public function attachTerms(string $contentId, array $termIds): void;

    /**
     * Detach taxonomy terms from a content item.
     *
     * @param list<string> $termIds UUIDv7 term identifiers
     */
    public function detachTerms(string $contentId, array $termIds): void;

    /**
     * Attach a single taxonomy term to multiple content items.
     *
     * @param list<string> $contentIds UUIDv7 content identifiers
     */
    public function bulkTag(array $contentIds, string $termId): void;

    /**
     * Detach a single taxonomy term from multiple content items.
     *
     * @param list<string> $contentIds UUIDv7 content identifiers
     */
    public function bulkUntag(array $contentIds, string $termId): void;
}
