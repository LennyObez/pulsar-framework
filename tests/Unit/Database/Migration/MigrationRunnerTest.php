<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationFile;
use Pulsar\Database\Migration\MigrationRecord;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Database\PdoConnection;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(MigrationRecord::class)]
#[CoversClass(MigrationFile::class)]
#[CoversClass(MigrationRepository::class)]
final class MigrationRunnerTest extends TestCase
{
    private PdoConnection $connection;
    private string $migrationsPath;
    private MigrationRepository $repository;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->migrationsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migration_test_' . bin2hex(random_bytes(8));
        mkdir($this->migrationsPath, 0o750, true);

        $this->repository = new MigrationRepository($this->migrationsPath);
        $this->runner = new MigrationRunner($this->connection, $this->repository, 'migrations');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->migrationsPath);
    }

    #[Test]
    public function ensureMigrationTableCreatesTable(): void
    {
        $this->runner->ensureMigrationTable();

        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");

        self::assertCount(1, $result->rows);
    }

    #[Test]
    public function ensureMigrationTableIsIdempotent(): void
    {
        $this->runner->ensureMigrationTable();
        $this->runner->ensureMigrationTable();

        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'");

        self::assertCount(1, $result->rows);
    }

    #[Test]
    public function runPendingReturnsEmptyWhenNoMigrations(): void
    {
        $applied = $this->runner->runPending();

        self::assertSame([], $applied);
    }

    #[Test]
    public function runPendingAppliesMigrations(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE users');
                }
            };
            PHP);

        $applied = $this->runner->runPending();

        self::assertSame(['20240101120000'], $applied);

        // Verify the table was created
        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        self::assertCount(1, $result->rows);
    }

    #[Test]
    public function runPendingSkipsAlreadyApplied(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE users');
                }
            };
            PHP);

        $firstRun = $this->runner->runPending();
        self::assertSame(['20240101120000'], $firstRun);

        $secondRun = $this->runner->runPending();
        self::assertSame([], $secondRun);
    }

    #[Test]
    public function rollbackLastBatchRollsBackMigrations(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->runner->runPending();
        $rolledBack = $this->runner->rollbackLastBatch();

        self::assertSame(['20240101120000'], $rolledBack);

        // Verify the table was dropped
        $result = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        self::assertCount(0, $result->rows);
    }

    #[Test]
    public function rollbackLastBatchReturnsEmptyWhenNothingApplied(): void
    {
        $rolledBack = $this->runner->rollbackLastBatch();

        self::assertSame([], $rolledBack);
    }

    #[Test]
    public function rollbackToRollsBackToTarget(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->writeMigrationFile('20240102120000', 'create_posts_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS posts');
                }
            };
            PHP);

        $this->runner->runPending();

        $rolledBack = $this->runner->rollbackTo('20240102120000');

        self::assertSame(['20240102120000'], $rolledBack);
    }

    #[Test]
    public function resetRollsBackAll(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->writeMigrationFile('20240102120000', 'create_posts_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS posts');
                }
            };
            PHP);

        $this->runner->runPending();
        $rolledBack = $this->runner->reset();

        self::assertCount(2, $rolledBack);
        // Reversed order
        self::assertSame('20240102120000', $rolledBack[0]);
        self::assertSame('20240101120000', $rolledBack[1]);
    }

    #[Test]
    public function resetReturnsEmptyWhenNothingApplied(): void
    {
        $rolledBack = $this->runner->reset();

        self::assertSame([], $rolledBack);
    }

    #[Test]
    public function getAppliedReturnsAppliedRecords(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->runner->runPending();
        $applied = $this->runner->getApplied();

        self::assertCount(1, $applied);
        self::assertInstanceOf(MigrationRecord::class, $applied[0]);
        self::assertSame('20240101120000', $applied[0]->version);
        self::assertSame(1, $applied[0]->batch);
    }

    #[Test]
    public function getCurrentBatchReturnsHighestBatch(): void
    {
        $this->runner->ensureMigrationTable();

        self::assertSame(0, $this->runner->getCurrentBatch());

        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->runner->runPending();

        self::assertSame(1, $this->runner->getCurrentBatch());
    }

    #[Test]
    public function multipleBatchesAssignIncrementingNumbers(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->runner->runPending();

        $this->writeMigrationFile('20240102120000', 'create_posts_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS posts');
                }
            };
            PHP);

        $this->runner->runPending();

        self::assertSame(2, $this->runner->getCurrentBatch());

        $applied = $this->runner->getApplied();
        self::assertSame(1, $applied[0]->batch);
        self::assertSame(2, $applied[1]->batch);
    }

    #[Test]
    public function getPendingReturnsUnAppliedMigrations(): void
    {
        $this->writeMigrationFile('20240101120000', 'create_users_table', <<<'PHP'
            <?php
            return new class implements \Pulsar\Database\Migration\MigrationInterface {
                public function up(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY)');
                }
                public function down(\Pulsar\Database\ConnectionInterface $conn): void {
                    $conn->execute('DROP TABLE IF EXISTS users');
                }
            };
            PHP);

        $this->runner->ensureMigrationTable();

        $pending = $this->runner->getPending();

        self::assertCount(1, $pending);
        self::assertSame('20240101120000', $pending[0]->version);
    }

    private function writeMigrationFile(string $version, string $name, string $content): void
    {
        $filename = $version . '_' . $name . '.php';
        file_put_contents($this->migrationsPath . DIRECTORY_SEPARATOR . $filename, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
