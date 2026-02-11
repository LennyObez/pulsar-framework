<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Http\RateLimit\SqliteRateLimiter;

use function bin2hex;
use function gc_collect_cycles;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(SqliteRateLimiter::class)]
#[CoversClass(RateLimitResult::class)]
final class SqliteRateLimiterTest extends TestCase
{
    private string $tempDir;

    /** @var array<int, SqliteRateLimiter> */
    private array $limiters = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_ratelimit_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        // Release PDO connections before deleting SQLite files (Windows file locks)
        foreach ($this->limiters as $i => $_) {
            unset($this->limiters[$i]);
        }
        gc_collect_cycles();
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function allowsRequestsWithinLimit(): void
    {
        $limiter = $this->createLimiter(3, 60);

        $result = $limiter->hit('key');

        self::assertTrue($result->allowed);
        self::assertSame(3, $result->limit);
        self::assertSame(2, $result->remaining);
    }

    #[Test]
    public function rejectsRequestsOverLimit(): void
    {
        $limiter = $this->createLimiter(2, 60);

        $limiter->hit('key');
        $limiter->hit('key');
        $result = $limiter->hit('key');

        self::assertFalse($result->allowed);
        self::assertTrue($result->exceeded());
        self::assertSame(0, $result->remaining);
        self::assertGreaterThan(0, $result->retryAfter);
    }

    #[Test]
    public function tracksKeysIndependently(): void
    {
        $limiter = $this->createLimiter(1, 60);

        $limiter->hit('key-a');
        $overA = $limiter->hit('key-a');
        $resultB = $limiter->hit('key-b');

        self::assertFalse($overA->allowed);
        self::assertTrue($resultB->allowed);
    }

    #[Test]
    public function attemptsReturnsCurrentCount(): void
    {
        $limiter = $this->createLimiter(10, 60);

        self::assertSame(0, $limiter->attempts('key'));

        $limiter->hit('key');
        $limiter->hit('key');

        self::assertSame(2, $limiter->attempts('key'));
    }

    #[Test]
    public function resetClearsCounter(): void
    {
        $limiter = $this->createLimiter(2, 60);

        $limiter->hit('key');
        $limiter->hit('key');
        $limiter->reset('key');

        $result = $limiter->hit('key');

        self::assertTrue($result->allowed);
        self::assertSame(1, $limiter->attempts('key'));
    }

    #[Test]
    public function multipleInstancesShareState(): void
    {
        $path = $this->dbPath();

        $limiter1 = $this->createLimiter(2, 60, $path);
        $limiter2 = $this->createLimiter(2, 60, $path);

        $limiter1->hit('shared-key');
        $limiter2->hit('shared-key');
        $result = $limiter1->hit('shared-key');

        self::assertFalse($result->allowed);
    }

    private function createLimiter(int $maxAttempts, int $windowSeconds, ?string $path = null): SqliteRateLimiter
    {
        $limiter = new SqliteRateLimiter($maxAttempts, $windowSeconds, $path ?? $this->dbPath());
        $this->limiters[] = $limiter;

        return $limiter;
    }

    private function dbPath(): string
    {
        return $this->tempDir . DIRECTORY_SEPARATOR . 'rate.sqlite';
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
