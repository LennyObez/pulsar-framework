<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\SqliteWalFactory;
use ValueError;

use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SqliteWalFactory::class)]
final class SqliteWalFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_sqlite_wal_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function createReturnsPdoInstance(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'test.db';

        $db = SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY)');

        self::assertInstanceOf(PDO::class, $db);
    }

    #[Test]
    public function createSetsWalJournalMode(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'wal.db';

        $db = SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');

        $stmt = $db->query('PRAGMA journal_mode');
        self::assertNotFalse($stmt);
        self::assertSame('wal', $stmt->fetchColumn());
    }

    #[Test]
    public function createSetsBusyTimeout(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'timeout.db';

        $db = SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');

        $stmt = $db->query('PRAGMA busy_timeout');
        self::assertNotFalse($stmt);
        self::assertSame('5000', (string) $stmt->fetchColumn());
    }

    #[Test]
    public function createSetsExceptionErrorMode(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'errmode.db';

        $db = SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');

        self::assertSame(PDO::ERRMODE_EXCEPTION, $db->getAttribute(PDO::ATTR_ERRMODE));
    }

    #[Test]
    public function createExecutesSchemaStatement(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'schema.db';
        $schema = 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)';

        $db = SqliteWalFactory::create($path, $schema);

        // Insert to confirm table exists
        $db->exec("INSERT INTO users (name) VALUES ('alice')");
        $stmt = $db->query('SELECT COUNT(*) FROM users');
        self::assertNotFalse($stmt);
        self::assertSame('1', (string) $stmt->fetchColumn());
    }

    #[Test]
    public function createCreatesDirectoryIfMissing(): void
    {
        $nestedDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep';
        $path = $nestedDir . DIRECTORY_SEPARATOR . 'auto.db';

        SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');

        self::assertTrue(is_dir($nestedDir));

        // Clean up nested dirs
        @unlink($path);
        @rmdir($nestedDir);
        @rmdir($this->tempDir . DIRECTORY_SEPARATOR . 'nested');
    }

    #[Test]
    public function createDatabaseFileIsWrittenToDisk(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'ondisk.db';

        SqliteWalFactory::create($path, 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)');

        self::assertTrue(is_file($path));
    }

    #[Test]
    public function createHandlesMultipleTableSchema(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'multi.db';
        $schema = <<<'SQL'
            CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT);
            CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT);
            SQL;

        $db = SqliteWalFactory::create($path, $schema);

        $db->exec("INSERT INTO users (name) VALUES ('bob')");
        $db->exec("INSERT INTO posts (user_id, title) VALUES (1, 'Hello')");

        $userStmt = $db->query('SELECT COUNT(*) FROM users');
        self::assertNotFalse($userStmt);
        $postStmt = $db->query('SELECT COUNT(*) FROM posts');
        self::assertNotFalse($postStmt);

        self::assertSame('1', (string) $userStmt->fetchColumn());
        self::assertSame('1', (string) $postStmt->fetchColumn());
    }

    #[Test]
    public function createThrowsForEmptySchema(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'empty_schema.db';

        $this->expectException(ValueError::class);

        SqliteWalFactory::create($path, '');
    }

    #[Test]
    public function createIsIdempotentOnExistingDatabase(): void
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'idempotent.db';
        $schema = 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, val TEXT)';

        $db1 = SqliteWalFactory::create($path, $schema);
        $db1->exec("INSERT INTO t (val) VALUES ('first')");

        $db2 = SqliteWalFactory::create($path, $schema);
        $stmt = $db2->query('SELECT COUNT(*) FROM t');
        self::assertNotFalse($stmt);

        self::assertSame('1', (string) $stmt->fetchColumn());
    }
}
