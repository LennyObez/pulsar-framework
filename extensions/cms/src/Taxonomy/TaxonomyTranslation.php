<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Taxonomy;

use Pulsar\Api\Api;

/**
 * Per-locale translation for a taxonomy definition.
 */
#[Api(since: '1.0.0')]
final readonly class TaxonomyTranslation
{
    /**
     * @param string $taxonomyId UUIDv7 FK taxonomies
     * @param string $locale BCP 47 locale code
     * @param string $name Display name
     * @param string|null $description Optional description
     */
    public function __construct(
        public string $taxonomyId,
        public string $locale,
        public string $name,
        public ?string $description,
    ) {}
}
