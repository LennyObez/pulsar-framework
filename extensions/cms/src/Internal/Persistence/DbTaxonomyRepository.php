<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
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

    private const array UPSERT_TAXONOMY_COLUMNS = ['id', 'tenant_id', 'slug', 'hierarchical', 'created_at'];
    private const array UPSERT_TAXONOMY_UPDATE = ['slug', 'hierarchical'];

    private const array UPSERT_TAXONOMY_TRANS_COLUMNS = ['taxonomy_id', 'locale', 'name', 'description'];
    private const array UPSERT_TAXONOMY_TRANS_UPDATE = ['name', 'description'];

    private const array UPSERT_TERM_COLUMNS = ['id', 'taxonomy_id', 'tenant_id', 'parent_id', 'sort_order', 'created_at'];
    private const array UPSERT_TERM_UPDATE = ['parent_id', 'sort_order'];

    private const array UPSERT_TERM_TRANS_COLUMNS = ['term_id', 'locale', 'name', 'slug', 'description', 'tenant_key', 'taxonomy_id'];
    private const array UPSERT_TERM_TRANS_UPDATE = ['name', 'slug', 'description'];

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findBySlug(string $slug, ?string $tenantId = null): ?Taxonomy
    {
        $resolved = $tenantId ?? $this->tenantId;
        $tenantKey = $resolved ?? self::SENTINEL_TENANT;

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
            $taxonomySql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_taxonomies',
                self::UPSERT_TAXONOMY_COLUMNS,
                ['id'],
                self::UPSERT_TAXONOMY_UPDATE,
            );

            $conn->execute($taxonomySql, [
                'id' => $taxonomy->id,
                'tenant_id' => $taxonomy->tenantId,
                'slug' => $taxonomy->slug,
                'hierarchical' => $taxonomy->hierarchical,
                'created_at' => $taxonomy->createdAt->format('c'),
            ]);

            $transSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_taxonomy_translations',
                self::UPSERT_TAXONOMY_TRANS_COLUMNS,
                ['taxonomy_id', 'locale'],
                self::UPSERT_TAXONOMY_TRANS_UPDATE,
            );

            foreach ($translations as $translation) {
                $conn->execute($transSql, [
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
            $termSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_taxonomy_terms',
                self::UPSERT_TERM_COLUMNS,
                ['id'],
                self::UPSERT_TERM_UPDATE,
            );

            $conn->execute($termSql, [
                'id' => $term->id,
                'taxonomy_id' => $term->taxonomyId,
                'tenant_id' => $term->tenantId,
                'parent_id' => $term->parentId,
                'sort_order' => $term->sortOrder,
                'created_at' => $term->createdAt->format('c'),
            ]);

            $tenantKey = $term->tenantId ?? self::SENTINEL_TENANT;

            $termTransSql = UpsertBuilder::compile(
                $conn->driver(),
                'cms_taxonomy_term_translations',
                self::UPSERT_TERM_TRANS_COLUMNS,
                ['term_id', 'locale'],
                self::UPSERT_TERM_TRANS_UPDATE,
            );

            foreach ($translations as $translation) {
                $conn->execute($termTransSql, [
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

    public function updateTermParent(string $termId, string $parentId): void
    {
        $this->connection->execute(
            'UPDATE cms_taxonomy_terms SET parent_id = :parent_id WHERE id = :id',
            ['parent_id' => $parentId, 'id' => $termId],
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
