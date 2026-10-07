<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;

/**
 * Add missing foreign key indexes to forum_posts.
 *
 * The edited_by column references an external user but lacked an index,
 * causing slow JOIN and WHERE queries on moderation audit trails.
 */
return new class implements MigrationInterface {
    private const string INDEX = 'idx_forum_posts_edited_by';
    private const string TABLE = 'forum_posts';

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(
            $connection->dialect()->compileCreateIndex(
                self::INDEX,
                self::TABLE,
                ['edited_by'],
                ifNotExists: true,
            ),
        );
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute(
            $connection->dialect()->compileDropIndex(self::INDEX, self::TABLE),
        );
    }
};
