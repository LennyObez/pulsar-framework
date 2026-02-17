<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Config\HistoryRetentionConfig;

#[CoversClass(HistoryRetentionConfig::class)]
final class HistoryRetentionConfigTest extends TestCase
{
    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $config = new HistoryRetentionConfig();

        self::assertSame(30, $config->maxAgeDays);
        self::assertSame(100_000, $config->maxRows);
        self::assertSame(6, $config->cleanupIntervalHours);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = HistoryRetentionConfig::fromArray([
            'max_age_days' => 7,
            'max_rows' => 50_000,
            'cleanup_interval_hours' => 12,
        ]);

        self::assertSame(7, $config->maxAgeDays);
        self::assertSame(50_000, $config->maxRows);
        self::assertSame(12, $config->cleanupIntervalHours);
    }

    #[Test]
    public function fromArrayWithEmptyDataUsesDefaults(): void
    {
        $config = HistoryRetentionConfig::fromArray([]);

        self::assertSame(30, $config->maxAgeDays);
        self::assertSame(100_000, $config->maxRows);
        self::assertSame(6, $config->cleanupIntervalHours);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function boundaryValuesProvider(): iterable
    {
        yield 'minimum retention' => [1, 1000, 1];
        yield 'large retention' => [365, 10_000_000, 24];
        yield 'zero age days' => [0, 100_000, 6];
    }

    #[Test]
    #[DataProvider('boundaryValuesProvider')]
    public function fromArrayPreservesBoundaryValues(int $days, int $rows, int $hours): void
    {
        $config = HistoryRetentionConfig::fromArray([
            'max_age_days' => $days,
            'max_rows' => $rows,
            'cleanup_interval_hours' => $hours,
        ]);

        self::assertSame($days, $config->maxAgeDays);
        self::assertSame($rows, $config->maxRows);
        self::assertSame($hours, $config->cleanupIntervalHours);
    }
}
