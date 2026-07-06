<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Monitor\CompositeSqlLogger;
use Pulsar\Database\Monitor\ProfilerSqlLogger;
use Pulsar\Database\Monitor\SqlLoggerInterface;
use Pulsar\Observability\Profiler\RequestProfiler;

#[CoversClass(ProfilerSqlLogger::class)]
#[CoversClass(CompositeSqlLogger::class)]
final class ProfilerSqlLoggerTest extends TestCase
{
    #[Test]
    public function forwardsQueriesToTheProfiler(): void
    {
        $profiler = new RequestProfiler(enabled: true);
        $logger = new ProfilerSqlLogger($profiler);

        $logger->log('SELECT * FROM users', [], 1.5, 3);
        $logger->log('SELECT 1', [], 0.5, 1);

        $profile = $profiler->finish('GET', '/', 200);

        self::assertSame(2, $profile->queryCount);
        self::assertEqualsWithDelta(2.0, $profile->queryTimeMs, 0.001);
    }

    #[Test]
    public function compositeFansOutToEverySink(): void
    {
        $a = $this->recordingLogger();
        $b = $this->recordingLogger();

        $composite = new CompositeSqlLogger([$a, $b]);
        $composite->log('SELECT 1', ['x'], 0.25, 1);

        self::assertSame(1, $a->calls);
        self::assertSame(1, $b->calls);
        self::assertSame('SELECT 1', $a->lastSql);
    }

    /**
     * @return SqlLoggerInterface&object{calls: int, lastSql: string}
     */
    private function recordingLogger(): SqlLoggerInterface
    {
        return new class implements SqlLoggerInterface {
            public int $calls = 0;
            public string $lastSql = '';

            public function log(string $sql, array $bindings, float $durationMs, int $rowCount): void
            {
                $this->calls++;
                $this->lastSql = $sql;
            }
        };
    }
}
