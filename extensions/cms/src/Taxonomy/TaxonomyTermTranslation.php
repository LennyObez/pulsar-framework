<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Per-locale translation for a taxonomy term.
 *
 * @psalm-api Public DTO returned from TaxonomyRepositoryInterface; consumed
 *            by content services and admin templates.
 */
#[Api(since: '1.0.0')]
final readonly class TaxonomyTermTranslation
{
    /**
     * @param string $termId UUIDv7 FK taxonomy_terms
     * @param string $locale BCP 47 locale code
     * @param string $name Display name
     * @param string $slug URL-safe identifier, unique per (taxonomy_id, locale, tenant_id)
     * @param string|null $description Optional description
     */
    public function __construct(
        public string $termId,
        public string $locale,
        public string $name,
        public string $slug,
        public ?string $description,
    ) {}
}
