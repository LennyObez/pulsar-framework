<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Migration\MigrationFile;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Migration\MigrationRepository;
use Pulsar\Database\Migration\MigrationVersionScheme;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(MigrationRepository::class)]
final class MigrationRepositoryTest extends TestCase
{
    private string $tempDir;
    private ?string $tempDir2 = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migration_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up primary temp dir
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        // Clean up secondary temp dir if created
        if ($this->tempDir2 !== null) {
            $files2 = glob($this->tempDir2 . '/*') ?: [];
            foreach ($files2 as $file) {
                unlink($file);
            }
            if (is_dir($this->tempDir2)) {
                rmdir($this->tempDir2);
            }
            $this->tempDir2 = null;
        }
    }

    private function createSecondDir(): string
    {
        $this->tempDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_migration_test_' . uniqid();
        mkdir($this->tempDir2, 0o755, true);

        return $this->tempDir2;
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
        $this->expectExceptionMessageIsOrContains('Duplicate migration version: 20240101120000');

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
    public function extractVersionAcceptsSeparatedFormat(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('20260203153000', $repo->extractVersion('2026_02_03_153000_create_users.php'));
    }

    #[Test]
    public function extractNameReturnsDescriptionPart(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('create_users_table', $repo->extractName('20240101120000_create_users_table.php'));
    }

    #[Test]
    public function extractNameReturnsDescriptionPartForSeparatedFormat(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('create_users_table', $repo->extractName('2024_01_01_120000_create_users_table.php'));
    }

    #[Test]
    public function discoverFindsSeparatedFormatFiles(): void
    {
        $this->createMigrationFile('2024_01_01_120000_create_users_table.php');
        $this->createMigrationFile('2024_01_02_120000_create_posts_table.php');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(2, $files);
        self::assertArrayHasKey('20240101120000', $files);
        self::assertArrayHasKey('20240102120000', $files);

        $usersMigration = $files['20240101120000'] ?? null;
        self::assertNotNull($usersMigration);
        self::assertSame('create_users_table', $usersMigration->name);
    }

    #[Test]
    public function discoverDetectsDuplicatesAcrossFormats(): void
    {
        // Same version expressed in compact and separated formats
        $this->createMigrationFile('20240101120000_first.php');
        $this->createMigrationFile('2024_01_01_120000_second.php');

        $repo = new MigrationRepository($this->tempDir);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate migration version: 20240101120000');

        $repo->discover();
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
        $this->expectExceptionMessageIsOrContains('must return a MigrationInterface instance');

        $repo->load($filePath);
    }

    // =========================================================================
    // Multi-path discovery tests
    // =========================================================================

    #[Test]
    public function discoverScansMultipleDirectories(): void
    {
        $dir2 = $this->createSecondDir();

        $this->createMigrationFile('20240101120000_create_users.php');
        $this->createMigrationFileIn($dir2, '20240201120000_create_posts.php');

        $repo = new MigrationRepository([$this->tempDir, $dir2]);
        $files = $repo->discover();

        self::assertCount(2, $files);
        self::assertArrayHasKey('20240101120000', $files);
        self::assertArrayHasKey('20240201120000', $files);
    }

    #[Test]
    public function discoverSkipsNonexistentPathsInArray(): void
    {
        $this->createMigrationFile('20240101120000_create_users.php');

        $repo = new MigrationRepository([$this->tempDir, '/nonexistent/path']);
        $files = $repo->discover();

        self::assertCount(1, $files);
        self::assertArrayHasKey('20240101120000', $files);
    }

    #[Test]
    public function discoverDetectsDuplicateVersionsAcrossDirectories(): void
    {
        $dir2 = $this->createSecondDir();

        $this->createMigrationFile('20240101120000_create_users.php');
        $this->createMigrationFileIn($dir2, '20240101120000_create_posts.php');

        $repo = new MigrationRepository([$this->tempDir, $dir2]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Duplicate migration version: 20240101120000');

        $repo->discover();
    }

    #[Test]
    public function constructorAcceptsSingleStringForBackwardCompat(): void
    {
        $this->createMigrationFile('20240101120000_create_users.php');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(1, $files);
    }

    #[Test]
    public function versionsReturnsAllVersionsAcrossPaths(): void
    {
        $dir2 = $this->createSecondDir();

        $this->createMigrationFile('20240101120000_first.php');
        $this->createMigrationFileIn($dir2, '20240201120000_second.php');

        $repo = new MigrationRepository([$this->tempDir, $dir2]);

        self::assertSame(['20240101120000', '20240201120000'], $repo->versions());
    }

    // =========================================================================
    // Sequential format tests
    // =========================================================================

    #[Test]
    public function extractVersionSupportsSequentialFormat(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('00000000000001', $repo->extractVersion('001_create_users.php'));
    }

    #[Test]
    public function extractVersionSupportsThreeDigitSequential(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('00000000000042', $repo->extractVersion('042_add_indexes.php'));
    }

    #[Test]
    public function extractNameSupportsSequentialFormat(): void
    {
        $repo = new MigrationRepository($this->tempDir);

        self::assertSame('create_users', $repo->extractName('001_create_users.php'));
    }

    #[Test]
    public function discoverFindsSequentialFormatFiles(): void
    {
        $this->createMigrationFile('001_create_users.php');
        $this->createMigrationFile('002_create_posts.php');

        $repo = new MigrationRepository($this->tempDir);
        $files = $repo->discover();

        self::assertCount(2, $files);

        // An unlabelled directory has no source name, so a sequential version is the
        // bare zero-padded number. Nothing about the directory reaches the version.
        $versions = array_map(strval(...), array_keys($files));
        $names = array_map(static fn(MigrationFile $f): string => $f->name, array_values($files));
        self::assertSame('00000000000001', $versions[0]);
        self::assertSame('00000000000002', $versions[1]);
        self::assertSame('create_users', $names[0]);
        self::assertSame('create_posts', $names[1]);
    }

    // =========================================================================
    // Version identity: a version belongs to the migration, not to the checkout
    // =========================================================================

    /**
     * The same source shipping the same file produces the same version from two
     * different directories.
     *
     * This is the regression that matters most in this class. Sequential versions used
     * to be qualified by `crc32b` of the ABSOLUTE migrations directory, so a checkout at
     * `/home/dev/app` and the same code deployed to `/var/www/app` recorded the same
     * migration under two different versions. On the deploy host every already-applied
     * migration then read as pending and `pulsar migrate` re-ran it against the
     * production database.
     */
    #[Test]
    public function aLabelledSourceKeepsItsVersionsWhenTheCheckoutMoves(): void
    {
        $deployDir = $this->createSecondDir();

        $this->createMigrationFile('001_create_cms_contents.php');
        $this->createMigrationFileIn($deployDir, '001_create_cms_contents.php');

        $onDeveloperMachine = new MigrationRepository(['ext:pulsar/cms' => $this->tempDir]);
        $onDeployHost = new MigrationRepository(['ext:pulsar/cms' => $deployDir]);

        self::assertSame(
            $onDeveloperMachine->versions(),
            $onDeployHost->versions(),
            'the version changed with the directory, so a deploy would re-run applied migrations',
        );
    }

    /**
     * Two sources shipping `001_` stay distinct, which is the reason a qualifier exists
     * at all — and the reason removing the path-derived one could not simply drop it.
     */
    #[Test]
    public function twoSourcesShippingTheSameNumberGetDistinctVersions(): void
    {
        $forumDir = $this->createSecondDir();

        $this->createMigrationFile('001_create_cms_contents.php');
        $this->createMigrationFileIn($forumDir, '001_create_forum_threads.php');

        $repo = new MigrationRepository([
            'ext:pulsar/cms' => $this->tempDir,
            'ext:pulsar/forum' => $forumDir,
        ]);

        $versions = $repo->versions();

        self::assertCount(2, $versions);
        self::assertNotSame($versions[0], $versions[1]);

        foreach ($versions as $version) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{4}_00000000000001$/', $version);
        }
    }

    /**
     * The qualifier is a function of the source name and of nothing else, so it can be
     * reproduced anywhere the name is known — including in the `UPDATE` an operator runs
     * to re-key a table written by an older Pulsar.
     */
    #[Test]
    public function theQualifierIsDerivedFromTheSourceName(): void
    {
        $this->createMigrationFile('001_create_cms_contents.php');

        $repo = new MigrationRepository(['ext:pulsar/cms' => $this->tempDir]);

        self::assertSame(
            [MigrationVersionScheme::prefixForSource('ext:pulsar/cms') . '_00000000000001'],
            $repo->versions(),
        );
    }

    /**
     * Timestamp versions never took a qualifier and still do not: they are already
     * globally unique, and qualifying them would have renamed the framework's own
     * migrations for no gain.
     */
    #[Test]
    public function timestampVersionsAreUnqualifiedEvenUnderALabelledSource(): void
    {
        $this->createMigrationFile('20240101120000_create_users.php');

        $repo = new MigrationRepository(['ext:pulsar/analytics' => $this->tempDir]);

        self::assertSame(['20240101120000'], $repo->versions());
    }

    #[Test]
    public function discoverMixesTimestampAndSequentialAcrossDirectories(): void
    {
        $dir2 = $this->createSecondDir();

        // Project uses timestamps
        $this->createMigrationFile('20240101120000_create_users.php');
        // Extension uses sequential
        $this->createMigrationFileIn($dir2, '001_create_cms_contents.php');

        $repo = new MigrationRepository([$this->tempDir, $dir2]);
        $files = $repo->discover();

        self::assertCount(2, $files);

        // Both files discovered: one timestamp, one prefixed sequential
        $versionStrings = array_map(strval(...), array_keys($files));
        $hasTimestamp = false;
        $hasSequential = false;
        foreach ($versionStrings as $v) {
            if ($v === '20240101120000') {
                $hasTimestamp = true;
            }
            if (str_contains($v, '00000000000001')) {
                $hasSequential = true;
            }
        }
        self::assertTrue($hasTimestamp, 'Timestamp migration should be discovered');
        self::assertTrue($hasSequential, 'Sequential migration should be discovered with path prefix');
    }

    // =========================================================================
    // Discovery caching tests
    // =========================================================================

    #[Test]
    public function discoverCachesResultOnSecondCall(): void
    {
        $this->createMigrationFile('20240101120000_create_users.php');

        $repo = new MigrationRepository($this->tempDir);

        $first = $repo->discover();
        self::assertCount(1, $first);

        // Add a new file after the first discover
        $this->createMigrationFile('20240201120000_create_posts.php');

        // Second call returns cached result (still 1 file)
        $second = $repo->discover();
        self::assertCount(1, $second);
        self::assertSame($first, $second);
    }

    #[Test]
    public function clearCacheForcesFreshScan(): void
    {
        $this->createMigrationFile('20240101120000_create_users.php');

        $repo = new MigrationRepository($this->tempDir);

        $first = $repo->discover();
        self::assertCount(1, $first);

        // Add a new file
        $this->createMigrationFile('20240201120000_create_posts.php');

        // Clear the cache and re-discover
        $repo->clearCache();
        $second = $repo->discover();
        self::assertCount(2, $second);
    }

    #[Test]
    public function versionsUsesCachedDiscovery(): void
    {
        $this->createMigrationFile('20240101120000_first.php');

        $repo = new MigrationRepository($this->tempDir);

        $versions1 = $repo->versions();
        self::assertSame(['20240101120000'], $versions1);

        // Add a new file without clearing cache
        $this->createMigrationFile('20240201120000_second.php');

        // versions() should still return cached data
        $versions2 = $repo->versions();
        self::assertSame(['20240101120000'], $versions2);

        // After clear, both files are returned
        $repo->clearCache();
        $versions3 = $repo->versions();
        self::assertSame(['20240101120000', '20240201120000'], $versions3);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createMigrationFile(string $filename): void
    {
        $this->createMigrationFileIn($this->tempDir, $filename);
    }

    private function createMigrationFileIn(string $directory, string $filename): void
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

        file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $content);
    }
}
