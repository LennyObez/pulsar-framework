<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;

/**
 * Performance indexes and foreign keys for the CMS tables, batch 23.
 *
 * ## Why the index list is data
 *
 * These nineteen indexes used to be written three times — once per engine — and the three
 * copies had already drifted apart. `idx_contents_published_at` carried its
 * `WHERE published_at IS NOT NULL` on SQLite and PostgreSQL and silently lost it on MySQL;
 * `idx_media_assets_visibility` indexed `(visibility, deleted_at)` on two engines and
 * `(visibility) WHERE deleted_at IS NULL` on the third. Nothing reported the divergence,
 * because nothing compared the copies.
 *
 * They are stated once now. The only difference that survives is the one that is real: an
 * engine with partial indexes filters on the predicate and leaves the filtered column out
 * of the key, and an engine without has to carry it in the key instead. That is asked of
 * the dialect as a capability rather than decided from the driver's name.
 *
 * ## What was broken
 *
 * `down()` wrote `DROP INDEX IF EXISTS <i> ON <t>` for MySQL and `DROP INDEX IF EXISTS <i>`
 * for the rest. MySQL accepts neither — `IF EXISTS` is a syntax error there in both forms —
 * so the branch written to accommodate it was the one that could not run. The rollback
 * therefore failed on the first index, on the one engine it had been written for.
 *
 * The MySQL creation guard also asked `information_schema.statistics` for an index name
 * without naming its table, so an index of the same name on any other table in the schema
 * counted as this one already existing.
 */
return new class implements MigrationInterface {
    /**
     * Every index this migration owns, and the two shapes each can take.
     *
     * `columns` is the key where the engine can filter with a predicate; `unfiltered` is
     * the key where it cannot, and the filtered column has to sit in the key instead.
     * They are the same list wherever the predicate does not remove a column from it.
     *
     * @var list<array{name: string, table: string, columns: list<string>, unfiltered: list<string>, where: ?string}>
     */
    private const array INDEXES = [
        [
            'name' => 'idx_contents_tenant_status',
            'table' => 'cms_contents',
            'columns' => ['tenant_id', 'status'],
            'unfiltered' => ['tenant_id', 'status', 'deleted_at'],
            'where' => 'deleted_at IS NULL',
        ],
        [
            'name' => 'idx_contents_author_created',
            'table' => 'cms_contents',
            'columns' => ['author_id', 'created_at'],
            'unfiltered' => ['author_id', 'created_at'],
            'where' => null,
        ],
        [
            'name' => 'idx_contents_published_at',
            'table' => 'cms_contents',
            'columns' => ['published_at'],
            'unfiltered' => ['published_at'],
            'where' => 'published_at IS NOT NULL',
        ],
        [
            'name' => 'idx_translations_path_locale',
            'table' => 'cms_content_translations',
            'columns' => ['path', 'locale'],
            'unfiltered' => ['path', 'locale'],
            'where' => null,
        ],
        [
            'name' => 'idx_comments_content_status',
            'table' => 'cms_comments',
            'columns' => ['content_id', 'status'],
            'unfiltered' => ['content_id', 'status', 'deleted_at'],
            'where' => 'deleted_at IS NULL',
        ],
        [
            'name' => 'idx_comments_author',
            'table' => 'cms_comments',
            'columns' => ['author_id'],
            'unfiltered' => ['author_id'],
            'where' => null,
        ],
        [
            'name' => 'idx_orders_customer_created',
            'table' => 'cms_orders',
            'columns' => ['customer_id', 'created_at'],
            'unfiltered' => ['customer_id', 'created_at'],
            'where' => null,
        ],
        [
            'name' => 'idx_orders_status_created',
            'table' => 'cms_orders',
            'columns' => ['status', 'created_at'],
            'unfiltered' => ['status', 'created_at'],
            'where' => null,
        ],
        [
            'name' => 'idx_order_items_order',
            'table' => 'cms_order_items',
            'columns' => ['order_id'],
            'unfiltered' => ['order_id'],
            'where' => null,
        ],
        [
            'name' => 'idx_products_tenant_status',
            'table' => 'cms_products',
            'columns' => ['tenant_id', 'status'],
            'unfiltered' => ['tenant_id', 'status'],
            'where' => null,
        ],
        [
            'name' => 'idx_products_sku',
            'table' => 'cms_products',
            'columns' => ['sku'],
            'unfiltered' => ['sku'],
            'where' => null,
        ],
        [
            'name' => 'idx_product_variants_product',
            'table' => 'cms_product_variants',
            'columns' => ['product_id', 'sort_order'],
            'unfiltered' => ['product_id', 'sort_order'],
            'where' => null,
        ],
        [
            'name' => 'idx_promotions_active_dates',
            'table' => 'cms_promotions',
            'columns' => ['is_active', 'starts_at', 'expires_at'],
            'unfiltered' => ['is_active', 'starts_at', 'expires_at'],
            'where' => null,
        ],
        [
            'name' => 'idx_media_assets_uploader',
            'table' => 'cms_media_assets',
            'columns' => ['uploader_id'],
            'unfiltered' => ['uploader_id'],
            'where' => null,
        ],
        [
            'name' => 'idx_media_assets_visibility',
            'table' => 'cms_media_assets',
            'columns' => ['visibility'],
            'unfiltered' => ['visibility', 'deleted_at'],
            'where' => 'deleted_at IS NULL',
        ],
        [
            'name' => 'idx_customers_user',
            'table' => 'cms_customers',
            'columns' => ['user_id'],
            'unfiltered' => ['user_id'],
            'where' => null,
        ],
        [
            'name' => 'idx_customers_email',
            'table' => 'cms_customers',
            'columns' => ['email'],
            'unfiltered' => ['email'],
            'where' => null,
        ],
        [
            'name' => 'idx_redirects_tenant_path',
            'table' => 'cms_redirects',
            'columns' => ['tenant_id', 'from_path'],
            'unfiltered' => ['tenant_id', 'from_path'],
            'where' => 'deleted_at IS NULL',
        ],
        [
            'name' => 'idx_coupons_promotion',
            'table' => 'cms_coupons',
            'columns' => ['promotion_id'],
            'unfiltered' => ['promotion_id'],
            'where' => null,
        ],
    ];

    /**
     * Foreign keys this migration owns: constraint name to table, column and referenced
     * table. Referenced column is `id` throughout.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array FOREIGN_KEYS = [
        'fk_order_items_order' => ['cms_order_items', 'order_id', 'cms_orders'],
        'fk_product_variants_product' => ['cms_product_variants', 'product_id', 'cms_products'],
        'fk_coupons_promotion' => ['cms_coupons', 'promotion_id', 'cms_promotions'],
        'fk_translations_content' => ['cms_content_translations', 'content_id', 'cms_contents'],
    ];

    public function up(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);
        $partial = $connection->dialect()->supportsPartialIndexes();

        foreach (self::INDEXES as $index) {
            $indexes->ensure(
                $index['table'],
                $index['name'],
                $partial ? $index['columns'] : $index['unfiltered'],
                where: $index['where'],
            );
        }

        // Foreign keys stay engine-shaped: nothing in the dialect models a constraint yet,
        // and SQLite cannot add one to an existing table at all.
        match ($connection->driver()) {
            Driver::SQLite => null,
            Driver::MySQL, Driver::PostgreSQL => $this->addForeignKeys($connection),
        };
    }

    public function down(ConnectionInterface $connection): void
    {
        $indexes = new IndexOperations($connection);

        foreach (self::INDEXES as $index) {
            $indexes->ensureAbsent($index['table'], $index['name']);
        }

        match ($connection->driver()) {
            Driver::SQLite => null,
            Driver::MySQL => $this->dropForeignKeysForMysql($connection),
            Driver::PostgreSQL => $this->dropForeignKeysForPostgreSql($connection),
        };
    }

    private function addForeignKeys(ConnectionInterface $connection): void
    {
        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $referenced]) {
            if ($this->foreignKeyExists($connection, $name)) {
                continue;
            }

            $connection->execute(
                "ALTER TABLE $table ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $referenced (id)",
            );
        }
    }

    /**
     * MySQL drops a foreign key by its own verb and accepts no `IF EXISTS`, so the
     * constraint has to be looked up first.
     */
    private function dropForeignKeysForMysql(ConnectionInterface $connection): void
    {
        foreach (self::FOREIGN_KEYS as $name => [$table]) {
            if ($this->foreignKeyExists($connection, $name)) {
                $connection->execute("ALTER TABLE $table DROP FOREIGN KEY $name");
            }
        }
    }

    private function dropForeignKeysForPostgreSql(ConnectionInterface $connection): void
    {
        foreach (self::FOREIGN_KEYS as $name => [$table]) {
            $connection->execute("ALTER TABLE $table DROP CONSTRAINT IF EXISTS $name");
        }
    }

    /**
     * Scoped to the connected schema on MySQL, where `DATABASE()` is what separates this
     * database's constraints from every other one on the server.
     */
    private function foreignKeyExists(ConnectionInterface $connection, string $name): bool
    {
        $scope = $connection->driver() === Driver::MySQL
            ? 'constraint_schema = DATABASE() AND '
            : '';

        $result = $connection->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.table_constraints '
            . "WHERE {$scope}constraint_name = :name AND constraint_type = 'FOREIGN KEY'",
            ['name' => $name],
        );

        return ($result->first()?->getInt('cnt') ?? 0) > 0;
    }
};
