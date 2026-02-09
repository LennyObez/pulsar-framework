<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function gc_collect_cycles;
use function is_dir;
use function mkdir;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\InMemoryTotpReplayGuard;
use Pulsar\Auth\TwoFactor\SqliteTotpReplayGuard;

use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

#[CoversClass(InMemoryTotpReplayGuard::class)]
#[CoversClass(SqliteTotpReplayGuard::class)]
final class TotpReplayGuardTest extends TestCase
{
    private string $tempDir;

    /** @var array<int, SqliteTotpReplayGuard> */
    private array $guards = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_totp_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        // Release PDO connections before deleting SQLite files (Windows file locks)
        foreach ($this->guards as $i => $_) {
            unset($this->guards[$i]);
        }
        gc_collect_cycles();
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function inMemoryAllowsFirstUse(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
    }

    #[Test]
    public function inMemoryRejectsReplay(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertFalse($guard->markUsed('user-1', '123456', 1000));
    }

    #[Test]
    public function inMemoryAllowsDifferentCodes(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertTrue($guard->markUsed('user-1', '654321', 1000));
    }

    #[Test]
    public function inMemoryAllowsDifferentIdentities(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertTrue($guard->markUsed('user-2', '123456', 1000));
    }

    #[Test]
    public function inMemoryAllowsReusAfterExpiry(): void
    {
        $guard = new InMemoryTotpReplayGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        // 91 seconds later — expired
        self::assertTrue($guard->markUsed('user-1', '123456', 1091));
    }

    #[Test]
    public function sqliteAllowsFirstUse(): void
    {
        $guard = $this->createSqliteGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
    }

    #[Test]
    public function sqliteRejectsReplay(): void
    {
        $guard = $this->createSqliteGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertFalse($guard->markUsed('user-1', '123456', 1000));
    }

    #[Test]
    public function sqliteAllowsDifferentCodes(): void
    {
        $guard = $this->createSqliteGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertTrue($guard->markUsed('user-1', '654321', 1000));
    }

    #[Test]
    public function sqliteAllowsDifferentIdentities(): void
    {
        $guard = $this->createSqliteGuard();

        self::assertTrue($guard->markUsed('user-1', '123456', 1000));
        self::assertTrue($guard->markUsed('user-2', '123456', 1000));
    }

    #[Test]
    public function sqliteCrossProcessSharedState(): void
    {
        $path = $this->dbPath();
        $guard1 = $this->createSqliteGuard($path);
        $guard2 = $this->createSqliteGuard($path);

        self::assertTrue($guard1->markUsed('user-1', '123456', 1000));
        self::assertFalse($guard2->markUsed('user-1', '123456', 1000));
    }

    private function createSqliteGuard(?string $path = null): SqliteTotpReplayGuard
    {
        $guard = new SqliteTotpReplayGuard($path ?? $this->dbPath());
        $this->guards[] = $guard;

        return $guard;
    }

    private function dbPath(): string
    {
        return $this->tempDir . DIRECTORY_SEPARATOR . 'totp.sqlite';
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
