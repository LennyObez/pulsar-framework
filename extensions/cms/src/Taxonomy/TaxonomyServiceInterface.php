<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Service interface for managing content-taxonomy term associations.
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
}
