<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Taxonomy\Taxonomy;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyTerm;

#[Internal(reason: 'Raw-DB repository — use TaxonomyRepositoryInterface for public API')]
final readonly class DbTaxonomyRepository implements TaxonomyRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT * FROM cms_taxonomies
        WHERE slug = :slug
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_TERMS = <<<'SQL'
        SELECT t.*
        FROM cms_taxonomy_terms t
        INNER JOIN cms_taxonomy_term_translations tt
            ON tt.term_id = t.id AND tt.locale = :locale
        WHERE t.taxonomy_id = :taxonomy_id
        SQL;

    private const string SQL_INSERT_TAXONOMY = <<<'SQL'
        INSERT INTO cms_taxonomies (id, tenant_id, slug, hierarchical, created_at)
        VALUES (:id, :tenant_id, :slug, :hierarchical, :created_at)
        ON CONFLICT (id) DO UPDATE SET
            slug = EXCLUDED.slug,
            hierarchical = EXCLUDED.hierarchical
        SQL;

    private const string SQL_UPSERT_TAXONOMY_TRANSLATION = <<<'SQL'
        INSERT INTO cms_taxonomy_translations (taxonomy_id, locale, name, description)
        VALUES (:taxonomy_id, :locale, :name, :description)
        ON CONFLICT (taxonomy_id, locale) DO UPDATE SET
            name = EXCLUDED.name,
            description = EXCLUDED.description
        SQL;

    private const string SQL_INSERT_TERM = <<<'SQL'
        INSERT INTO cms_taxonomy_terms (id, taxonomy_id, tenant_id, parent_id, sort_order, created_at)
        VALUES (:id, :taxonomy_id, :tenant_id, :parent_id, :sort_order, :created_at)
        ON CONFLICT (id) DO UPDATE SET
            parent_id = EXCLUDED.parent_id,
            sort_order = EXCLUDED.sort_order
        SQL;

    private const string SQL_UPSERT_TERM_TRANSLATION = <<<'SQL'
        INSERT INTO cms_taxonomy_term_translations
            (term_id, locale, name, slug, description, tenant_key, taxonomy_id)
        VALUES (:term_id, :locale, :name, :slug, :description, :tenant_key, :taxonomy_id)
        ON CONFLICT (term_id, locale) DO UPDATE SET
            name = EXCLUDED.name,
            slug = EXCLUDED.slug,
            description = EXCLUDED.description
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findBySlug(string $slug, ?string $tenantId = null): ?Taxonomy
    {
        $tenantKey = ($tenantId ?? $this->tenantId) ?? self::SENTINEL_TENANT;

        $result = $this->connection->query(self::SQL_FIND_BY_SLUG, [
            'slug' => $slug,
            'tenant_key' => $tenantKey,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateTaxonomy($row);
    }

    public function findTerms(string $taxonomyId, string $locale, ?string $parentId = null): array
    {
        $sql = self::SQL_FIND_TERMS;
        $bindings = ['taxonomy_id' => $taxonomyId, 'locale' => $locale];

        if ($parentId !== null) {
            $sql .= ' AND t.parent_id = :parent_id';
            $bindings['parent_id'] = $parentId;
        } else {
            $sql .= ' AND t.parent_id IS NULL';
        }

        $sql .= ' ORDER BY t.sort_order ASC';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrateTerm(...));
    }

    public function save(Taxonomy $taxonomy, array $translations): void
    {
        $this->connection->transaction(function (ConnectionInterface $conn) use ($taxonomy, $translations): void {
            $conn->execute(self::SQL_INSERT_TAXONOMY, [
                'id' => $taxonomy->id,
                'tenant_id' => $taxonomy->tenantId,
                'slug' => $taxonomy->slug,
                'hierarchical' => $taxonomy->hierarchical,
                'created_at' => $taxonomy->createdAt->format('c'),
            ]);

            foreach ($translations as $translation) {
                $conn->execute(self::SQL_UPSERT_TAXONOMY_TRANSLATION, [
                    'taxonomy_id' => $translation->taxonomyId,
                    'locale' => $translation->locale,
                    'name' => $translation->name,
                    'description' => $translation->description,
                ]);
            }
        });
    }

    public function saveTerm(TaxonomyTerm $term, array $translations): void
    {
        $this->connection->transaction(function (ConnectionInterface $conn) use ($term, $translations): void {
            $conn->execute(self::SQL_INSERT_TERM, [
                'id' => $term->id,
                'taxonomy_id' => $term->taxonomyId,
                'tenant_id' => $term->tenantId,
                'parent_id' => $term->parentId,
                'sort_order' => $term->sortOrder,
                'created_at' => $term->createdAt->format('c'),
            ]);

            $tenantKey = $term->tenantId ?? self::SENTINEL_TENANT;

            foreach ($translations as $translation) {
                $conn->execute(self::SQL_UPSERT_TERM_TRANSLATION, [
                    'term_id' => $translation->termId,
                    'locale' => $translation->locale,
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'description' => $translation->description,
                    'tenant_key' => $tenantKey,
                    'taxonomy_id' => $term->taxonomyId,
                ]);
            }
        });
    }

    private static function hydrateTaxonomy(Row $row): Taxonomy
    {
        return new Taxonomy(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            slug: $row->getString('slug'),
            hierarchical: $row->getBool('hierarchical'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    private static function hydrateTerm(Row $row): TaxonomyTerm
    {
        return new TaxonomyTerm(
            id: $row->getString('id'),
            taxonomyId: $row->getString('taxonomy_id'),
            tenantId: $row->getNullableString('tenant_id'),
            parentId: $row->getNullableString('parent_id'),
            sortOrder: $row->getInt('sort_order'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
