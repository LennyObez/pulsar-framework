<?php

declare(strict_types=1);

namespace Pulsar\Testing\Database;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Pulsar\Api\Api;
use Pulsar\Database\Migration\MigrationRunner;

/**
 * Runs database migrations before the test suite and rolls them back after.
 *
 * Use this trait in integration tests that need a clean database state.
 * Requires implementing `getMigrationRunner()` to provide the migration runner.
 *
 * Usage:
 *   class MyIntegrationTest extends TestCase
 *   {
 *       use RefreshDatabase;
 *
 *       protected function getMigrationRunner(): MigrationRunner
 *       {
 *           return $this->app->get(MigrationRunner::class);
 *       }
 *   }
 * @api
 */
#[Api(since: '1.0.0')]
trait RefreshDatabase
{
    /**
     * Get the migration runner instance.
     */
    abstract protected function getMigrationRunner(): MigrationRunner;

    /**
     * Migrate the database before each test.
     */
    #[Before]
    protected function refreshDatabase(): void
    {
        $this->getMigrationRunner()->runPending();
    }

    /**
     * Roll back migrations after each test.
     */
    #[After]
    protected function rollbackDatabase(): void
    {
        $this->getMigrationRunner()->rollbackLastBatch();
    }
}
