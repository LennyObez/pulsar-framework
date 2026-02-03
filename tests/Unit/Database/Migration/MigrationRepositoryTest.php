<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use function file_put_contents;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Migration\MigrationRepository;

use function sys_get_temp_dir;
use function unlink;

#[CoversClass(MigrationRepository::class)]
final class MigrationRepositoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migration_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function discoverReturnsEmptyArrayForNonExistentDirectory(): void
    {
        $repo = new MigrationRepository('/nonexistent/path');

        self::assertSame([], $repo->discover());
    }

    #[Test]
    public function discoverReturnsEmptyArrayForEmptyDirectory(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame([], $repo->discover());
    }

    #[Test]
    public function discoverFindsMigrationFiles(): void
    {
        $this->createMigrationFile('20240101120000_create_users_table.php');
        $this->createMigrationFile('20240102120000_create_posts_table.php');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(2, $files);
        self::assertArrayHasKey('20240101120000', $files);
        self::assertArrayHasKey('20240102120000', $files);
    }

    #[Test]
    public function discoverSortsByVersion(): void
    {
        $this->createMigrationFile('20240301120000_third.php');
        $this->createMigrationFile('20240101120000_first.php');
        $this->createMigrationFile('20240201120000_second.php');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();
        $versions = array_map(strval(...), array_keys($files));

        self::assertSame(['20240101120000', '20240201120000', '20240301120000'], $versions);
    }

    #[Test]
    public function discoverIgnoresNonPhpFiles(): void
    {
        $this->createMigrationFile('20240101120000_migration.php');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'readme.txt', 'not a migration');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(1, $files);
    }

    #[Test]
    public function discoverIgnoresFilesWithoutTimestampPrefix(): void
    {
        $this->createMigrationFile('20240101120000_valid.php');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'invalid_name.php', '<?php return null;');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(1, $files);
    }

    #[Test]
    public function discoverThrowsOnDuplicateVersion(): void
    {
        // Create two files with the same timestamp but different names
        $this->createMigrationFile('20240101120000_first.php');
        $this->createMigrationFile('20240101120000_second.php');

        $repo = new MigrationRepository($this->tempDir);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Duplicate migration version: 20240101120000');

        $repo->discover();
    }

    #[Test]
    public function extractVersionReturnsTimestamp(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('20240101120000', $repo->extractVersion('20240101120000_create_users_table.php'));
    }

    #[Test]
    public function extractVersionReturnsNullForInvalidFilename(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertNull($repo->extractVersion('invalid_migration.php'));
    }

    #[Test]
    public function extractNameReturnsDescriptionPart(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('create_users_table', $repo->extractName('20240101120000_create_users_table.php'));
    }

    #[Test]
    public function loadReturnsMigrationInstance(): void
    {
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . '20240101120000_test.php';
        file_put_contents($filePath, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void {}
                public function down(ConnectionInterface $connection): void {}
            };
            PHP);

        $repo = new MigrationRepository($this->tempDir);
        $migration = $repo->load($filePath);

        self::assertInstanceOf(MigrationInterface::class, $migration);
    }

    #[Test]
    public function loadThrowsForInvalidFile(): void
    {
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . '20240101120000_invalid.php';
        file_put_contents($filePath, '<?php return "not a migration";');

        $repo = new MigrationRepository($this->tempDir);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('must return a MigrationInterface instance');

        $repo->load($filePath);
    }

    private function createMigrationFile(string $filename): void
    {
        $content = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Migration\MigrationInterface;

            return new class implements MigrationInterface {
                public function up(ConnectionInterface $connection): void {}
                public function down(ConnectionInterface $connection): void {}
            };
            PHP;

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . $filename, $content);
    }
}
