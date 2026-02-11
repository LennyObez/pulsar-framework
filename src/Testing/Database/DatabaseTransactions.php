<?php

declare(strict_types=1);

namespace Pulsar\Testing\Database;

use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Pulsar\Api\Api;

/**
 * Wraps each test in a database transaction that rolls back after the test.
 *
 * This is faster than RefreshDatabase for tests that don't need schema changes,
 * as it avoids running migrations and instead simply rolls back data changes.
 *
 * Usage:
 *   class MyTest extends TestCase
 *   {
 *       use DatabaseTransactions;
 *
 *       protected function getTransactionConnection(): PDO
 *       {
 *           return $this->app->get(PDO::class);
 *       }
 *   }
 */
#[Api(since: '1.0.0')]
trait DatabaseTransactions
{
    /**
     * Get the PDO connection for transaction management.
     */
    abstract protected function getTransactionConnection(): PDO;

    /**
     * Begin a transaction before each test.
     */
    #[Before]
    protected function beginDatabaseTransaction(): void
    {
        $this->getTransactionConnection()->beginTransaction();
    }

    /**
     * Roll back the transaction after each test.
     */
    #[After]
    protected function rollbackDatabaseTransaction(): void
    {
        $connection = $this->getTransactionConnection();

        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
}
