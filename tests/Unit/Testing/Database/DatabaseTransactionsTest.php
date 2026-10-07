<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Database;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Database\DatabaseTransactions;

/**
 * Verifies that DatabaseTransactions composes into a test class.
 */
final class DatabaseTransactionsTest extends TestCase
{
    use DatabaseTransactions;

    private ?PDO $pdo = null;

    protected function getTransactionConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->exec('CREATE TABLE items (id INTEGER, label TEXT)');
        }

        return $this->pdo;
    }

    #[Test]
    public function transaction_is_active_during_test(): void
    {
        self::assertTrue($this->getTransactionConnection()->inTransaction());
    }
}
