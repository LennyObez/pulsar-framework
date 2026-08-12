<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();
        $indexes = new IndexOperations($connection);

        $connection->execute(ForumDdl::adapt(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_category_translations (
                id VARCHAR(36) NOT NULL,
                category_id VARCHAR(36) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_cat_translation_category FOREIGN KEY (category_id) REFERENCES forum_categories (id) ON DELETE CASCADE
            )
            SQL, $driver));

        $indexes->ensure(
            'forum_category_translations',
            'uq_cat_translation_locale',
            ['category_id', 'locale'],
            unique: true,
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_category_translations');
    }
};
