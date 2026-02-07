<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Search;

use Pulsar\Database\ConnectionInterface;

/**
 * Recomputes the tsvector search_vector column for a content translation.
 *
 * Assigns PostgreSQL text search weights:
 * - A: title (highest relevance)
 * - B: headings + custom fields
 * - C: body plaintext
 * - D: taxonomy terms (lowest relevance)
 */
final readonly class SearchVectorComputer
{
    private const string SQL_UPDATE_VECTOR = <<<'SQL'
        UPDATE cms_content_translations
        SET search_vector =
            setweight(to_tsvector(:regconfig, COALESCE(title, '')), 'A') ||
            setweight(to_tsvector(:regconfig, COALESCE(headings_text, '')), 'B') ||
            setweight(to_tsvector(:regconfig, COALESCE(custom_fields_text, '')), 'B') ||
            setweight(to_tsvector(:regconfig, COALESCE(body_plaintext, '')), 'C') ||
            setweight(to_tsvector(:regconfig, COALESCE(taxonomy_terms_text, '')), 'D')
        WHERE id = :translation_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Recompute and persist the search_vector for a single translation.
     */
    public function computeForTranslation(string $translationId, string $locale): void
    {
        $regconfig = LocaleRegconfigMap::resolve($locale);

        $this->connection->execute(self::SQL_UPDATE_VECTOR, [
            'regconfig' => $regconfig,
            'translation_id' => $translationId,
        ]);
    }
}
