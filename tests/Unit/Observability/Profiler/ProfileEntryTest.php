<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Profiler\ProfileEntry;

#[CoversClass(ProfileEntry::class)]
final class ProfileEntryTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $entry = new ProfileEntry(
            category: 'database',
            label: 'SELECT users',
            startNs: 1_000_000,
            endNs: 3_500_000,
            metadata: ['table' => 'users'],
        );

        self::assertSame('database', $entry->category);
        self::assertSame('SELECT users', $entry->label);
        self::assertSame(1_000_000, $entry->startNs);
        self::assertSame(3_500_000, $entry->endNs);
        self::assertSame(['table' => 'users'], $entry->metadata);
    }

    #[Test]
    public function metadataDefaultsToEmptyArray(): void
    {
        $entry = new ProfileEntry(
            category: 'controller',
            label: 'HomeController::index',
            startNs: 0,
            endNs: 1_000_000,
        );

        self::assertSame([], $entry->metadata);
    }

    #[Test]
    public function durationNsCalculatesDifference(): void
    {
        $entry = new ProfileEntry('middleware', 'auth', startNs: 1_000_000, endNs: 5_000_000);

        self::assertSame(4_000_000, $entry->durationNs());
    }

    #[Test]
    public function durationMsConvertsFromNanoseconds(): void
    {
        $entry = new ProfileEntry('view', 'render', startNs: 0, endNs: 2_500_000);

        self::assertSame(2.5, $entry->durationMs());
    }

    #[Test]
    public function durationMsIsZeroForInstantOperation(): void
    {
        $entry = new ProfileEntry('cache', 'hit', startNs: 1_000_000, endNs: 1_000_000);

        self::assertSame(0.0, $entry->durationMs());
    }

    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $entry = new ProfileEntry(
            category: 'database',
            label: 'INSERT order',
            startNs: 10_000_000,
            endNs: 15_500_000,
            metadata: ['query_id' => 42],
        );

        $array = $entry->toArray();

        self::assertSame('database', $array['category']);
        self::assertSame('INSERT order', $array['label']);
        self::assertSame(10_000_000, $array['start_ns']);
        self::assertSame(15_500_000, $array['end_ns']);
        self::assertSame(5.5, $array['duration_ms']);
        self::assertSame(['query_id' => 42], $array['metadata']);
    }

    #[Test]
    public function toArrayRoundsDurationToThreeDecimals(): void
    {
        // 1_234_567 ns = 1.234567 ms, should round to 1.235
        $entry = new ProfileEntry('custom', 'op', startNs: 0, endNs: 1_234_567);

        $array = $entry->toArray();

        self::assertSame(1.235, $array['duration_ms']);
    }

    #[Test]
    public function largeDurationIsCalculatedCorrectly(): void
    {
        $entry = new ProfileEntry('controller', 'slow', startNs: 0, endNs: 5_000_000_000);

        self::assertSame(5000.0, $entry->durationMs());
    }
}
