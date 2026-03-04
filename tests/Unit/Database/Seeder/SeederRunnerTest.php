<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Seeder;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Exception\DatabaseException;
use Pulsar\Database\Seeder\SeederRunner;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;

final class SeederRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_seeder_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /**
     * Safely remove a test-owned directory and its contents.
     *
     * Only operates on paths under the system temp directory
     * to prevent accidental deletion of unrelated files.
     */
    private static function removeDirectory(string $dir): void
    {
        $realDir = realpath($dir);
        $realTmp = realpath(sys_get_temp_dir());

        if ($realDir === false || $realTmp === false || !str_starts_with($realDir, $realTmp)) {
            return;
        }

        $entries = scandir($realDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $realDir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($realDir);
    }

    #[Test]
    public function discoverReturnsEmptyForNonExistentDir(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, '/nonexistent/path');

        self::assertSame([], $runner->discover());
    }

    #[Test]
    public function discoverReturnsEmptyForEmptyDir(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        self::assertSame([], $runner->discover());
    }

    #[Test]
    public function discoverFindsSeederFiles(): void
    {
        file_put_contents($this->tempDir . '/UserSeeder.php', $this->seederContent());

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $entries = $runner->discover();

        self::assertCount(1, $entries);
        self::assertSame('UserSeeder', $entries[0]->name);
    }

    #[Test]
    public function discoverReturnsSortedByName(): void
    {
        file_put_contents($this->tempDir . '/ZebrasSeeder.php', $this->seederContent());
        file_put_contents($this->tempDir . '/AlphaSeeder.php', $this->seederContent());

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $entries = $runner->discover();

        self::assertCount(2, $entries);
        self::assertSame('AlphaSeeder', $entries[0]->name);
        self::assertSame('ZebrasSeeder', $entries[1]->name);
    }

    #[Test]
    public function runAllExecutesDiscoveredSeeders(): void
    {
        file_put_contents($this->tempDir . '/TestSeeder.php', $this->seederContent());

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $executed = $runner->runAll();

        self::assertSame(['test-seeder'], $executed);
    }

    #[Test]
    public function runAllReturnsEmptyWhenNoSeeders(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $executed = $runner->runAll();

        self::assertSame([], $executed);
    }

    #[Test]
    public function runByNameThrowsForMissingSeeder(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('not found');

        $runner->runByName('NonExistentSeeder');
    }

    #[Test]
    public function runByNameExecutesSpecificSeeder(): void
    {
        file_put_contents($this->tempDir . '/SpecificSeeder.php', $this->seederContent());

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        // Verify seeder is discovered before running
        $discovered = $runner->discover();
        self::assertCount(1, $discovered);
        self::assertSame('SpecificSeeder', $discovered[0]->name);

        // Running by name should succeed without throwing
        $runner->runByName('SpecificSeeder');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function runAllThrowsOnInvalidSeederFile(): void
    {
        file_put_contents($this->tempDir . '/BadSeeder.php', '<?php return "not a seeder";');

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Invalid seeder');

        $runner->runAll();
    }

    #[Test]
    public function runAllIncludesOriginalErrorMessageOnFailure(): void
    {
        $seederContent = <<<'PHP'
            <?php
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Seeder\SeederInterface;
            return new class implements SeederInterface {
                public function identifier(): string
                {
                    return 'failing-seeder';
                }
                public function run(ConnectionInterface $connection): void
                {
                    throw new \RuntimeException('Table "doc_versions" does not exist');
                }
            };
            PHP;

        file_put_contents($this->tempDir . '/FailingSeeder.php', $seederContent);

        $connection = $this->createStub(ConnectionInterface::class);
        $runner = new SeederRunner($connection, $this->tempDir);

        try {
            $runner->runAll();
            self::fail('Expected DatabaseException was not thrown');
        } catch (DatabaseException $e) {
            // The error message must include BOTH the seeder name AND the cause
            self::assertStringContainsString('FailingSeeder', $e->getMessage());
            self::assertStringContainsString('Table "doc_versions" does not exist', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'Original exception must be chained');
        }
    }

    private function seederContent(): string
    {
        return <<<'PHP'
            <?php
            use Pulsar\Database\ConnectionInterface;
            use Pulsar\Database\Seeder\SeederInterface;
            return new class implements SeederInterface {
                public function identifier(): string
                {
                    return 'test-seeder';
                }
                public function run(ConnectionInterface $connection): void
                {
                    // Test seeder — no-op
                }
            };
            PHP;
    }
}
