<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Extension\Forum\Migration\ForumDdl;

return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $driver = $connection->driver();

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

        $connection->execute(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_cat_translation_locale
                ON forum_category_translations (category_id, locale)
            SQL);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS forum_category_translations');
    }
};
