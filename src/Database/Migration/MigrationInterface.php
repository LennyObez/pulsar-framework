<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Interface for database migrations.
 *
 * Migrations should be returned as anonymous classes from migration files:
 *
 * ```php
 * return new class implements MigrationInterface {
 *     public function up(ConnectionInterface $connection): void { ... }
 *     public function down(ConnectionInterface $connection): void { ... }
 * };
 * ```
 * @api
 */
#[Api(since: '1.0.0')]
interface MigrationInterface
{
    /**
     * Run the migration (apply schema changes).
     */
    public function up(ConnectionInterface $connection): void;

    /**
     * Reverse the migration (undo schema changes).
     */
    public function down(ConnectionInterface $connection): void;
}
