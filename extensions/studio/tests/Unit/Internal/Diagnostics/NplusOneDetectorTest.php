<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\NplusOneDetector;

final class NplusOneDetectorTest extends TestCase
{
    #[Test]
    public function noViolationsWhenBelowThreshold(): void
    {
        $detector = new NplusOneDetector(threshold: 5);

        for ($i = 0; $i < 4; $i++) {
            $detector->record('SELECT * FROM users WHERE id = ' . $i, 1.0);
        }

        self::assertFalse($detector->hasViolations());
        self::assertSame([], $detector->violations());
    }

    #[Test]
    public function detectsViolationAtThreshold(): void
    {
        $detector = new NplusOneDetector(threshold: 3);

        // Same structural query repeated 3 times
        $detector->record('SELECT * FROM users WHERE id = 1', 2.0);
        $detector->record('SELECT * FROM users WHERE id = 2', 3.0);
        $detector->record('SELECT * FROM users WHERE id = 3', 1.5);

        self::assertTrue($detector->hasViolations());

        $violations = $detector->violations();
        self::assertCount(1, $violations);
        self::assertSame(3, $violations[0]->count);
        self::assertEqualsWithDelta(6.5, $violations[0]->totalDurationMs, 0.001);
    }

    #[Test]
    public function differentQueriesAreGroupedSeparately(): void
    {
        $detector = new NplusOneDetector(threshold: 2);

        $detector->record('SELECT * FROM users WHERE id = 1', 1.0);
        $detector->record('SELECT * FROM users WHERE id = 2', 1.0);
        $detector->record('SELECT * FROM orders WHERE user_id = 1', 1.0);

        $violations = $detector->violations();
        self::assertCount(1, $violations);
        self::assertStringContainsString('users', $violations[0]->normalizedSql);
    }

    #[Test]
    public function resetClearsAllState(): void
    {
        $detector = new NplusOneDetector(threshold: 2);

        $detector->record('SELECT * FROM users WHERE id = 1', 1.0);
        $detector->record('SELECT * FROM users WHERE id = 2', 1.0);

        self::assertTrue($detector->hasViolations());

        $detector->reset();

        self::assertFalse($detector->hasViolations());
        self::assertSame(0, $detector->distinctQueryCount());
    }

    #[Test]
    public function topGroupsReturnsLimitedResults(): void
    {
        $detector = new NplusOneDetector(threshold: 1);

        for ($i = 0; $i < 5; $i++) {
            $detector->record('SELECT * FROM table_a WHERE id = ' . $i, 1.0);
        }
        for ($i = 0; $i < 3; $i++) {
            $detector->record('SELECT * FROM table_b WHERE id = ' . $i, 1.0);
        }

        $top = $detector->topGroups(1);
        self::assertCount(1, $top);
        self::assertSame(5, $top[0]->count);
    }

    #[Test]
    public function toArrayExportsViolations(): void
    {
        $detector = new NplusOneDetector(threshold: 2);

        $detector->record('SELECT * FROM users WHERE id = 1', 1.0);
        $detector->record('SELECT * FROM users WHERE id = 2', 2.0);

        $array = $detector->toArray();
        self::assertCount(1, $array);
        self::assertArrayHasKey('fingerprint', $array[0]);
        self::assertArrayHasKey('sql', $array[0]);
        self::assertArrayHasKey('count', $array[0]);
        self::assertArrayHasKey('total_duration_ms', $array[0]);
        self::assertSame(2, $array[0]['count']);
    }

    #[Test]
    public function maxGroupsLimitsTracking(): void
    {
        $detector = new NplusOneDetector(threshold: 1, maxGroups: 2);

        $detector->record('SELECT * FROM table_a WHERE id = 1', 1.0);
        $detector->record('SELECT * FROM table_b WHERE id = 1', 1.0);
        $detector->record('SELECT * FROM table_c WHERE id = 1', 1.0);

        // Only 2 groups should be tracked
        self::assertSame(2, $detector->distinctQueryCount());
    }

    #[Test]
    public function thresholdAccessor(): void
    {
        $detector = new NplusOneDetector(threshold: 7);
        self::assertSame(7, $detector->threshold());
    }

    #[Test]
    public function violationsSortedByCountDescending(): void
    {
        $detector = new NplusOneDetector(threshold: 2);

        // 3 executions of query A
        for ($i = 0; $i < 3; $i++) {
            $detector->record('SELECT * FROM orders WHERE id = ' . $i, 1.0);
        }

        // 5 executions of query B
        for ($i = 0; $i < 5; $i++) {
            $detector->record('SELECT * FROM products WHERE id = ' . $i, 1.0);
        }

        $violations = $detector->violations();
        self::assertCount(2, $violations);
        self::assertSame(5, $violations[0]->count);
        self::assertSame(3, $violations[1]->count);
    }
}
