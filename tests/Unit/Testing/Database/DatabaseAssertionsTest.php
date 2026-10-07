<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Database;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Testing\Database\DatabaseAssertions;

/**
 * Verifies that DatabaseAssertions work with a real SQLite database.
 */
final class DatabaseAssertionsTest extends TestCase
{
    use DatabaseAssertions;

    private ?PDO $pdo = null;

    protected function getDatabaseConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
        }

        return $this->pdo;
    }

    #[Test]
    public function assert_database_has_finds_matching_row(): void
    {
        $this->getDatabaseConnection()->exec("INSERT INTO users VALUES (1, 'Alice')");

        $this->assertDatabaseHas('users', ['name' => 'Alice']);
    }

    #[Test]
    public function assert_database_missing_passes_for_absent_row(): void
    {
        $this->assertDatabaseMissing('users', ['name' => 'nonexistent']);
    }

    #[Test]
    public function assert_database_count_verifies_row_count(): void
    {
        $this->getDatabaseConnection()->exec("INSERT INTO users VALUES (1, 'php')");
        $this->getDatabaseConnection()->exec("INSERT INTO users VALUES (2, 'testing')");

        $this->assertDatabaseCount('users', 2);
    }

    #[Test]
    public function assert_database_count_with_criteria(): void
    {
        $this->getDatabaseConnection()->exec("INSERT INTO users VALUES (1, 'Alice')");
        $this->getDatabaseConnection()->exec("INSERT INTO users VALUES (2, 'Bob')");

        $this->assertDatabaseCount('users', 1, ['name' => 'Alice']);
    }
}
