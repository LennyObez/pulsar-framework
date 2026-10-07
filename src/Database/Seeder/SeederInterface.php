<?php

declare(strict_types=1);

namespace Pulsar\Database\Seeder;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Interface for database seeders.
 *
 * Seeders populate database tables with initial or test data.
 * Each seeder class handles one logical unit of seeding.
 * @api
 */
#[Api(since: '1.0.0')]
interface SeederInterface
{
    /**
     * Unique identifier for this seeder.
     *
     * Used to track which seeders have been executed and to prevent
     * duplicate seeding. Defaults to the class name if not overridden.
     */
    public function identifier(): string;

    /**
     * Run the seeder.
     */
    public function run(ConnectionInterface $connection): void;
}
