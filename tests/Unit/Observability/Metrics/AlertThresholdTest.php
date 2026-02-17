<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\AlertFiring;
use Pulsar\Observability\Metrics\AlertThreshold;

#[CoversClass(AlertThreshold::class)]
#[CoversClass(AlertFiring::class)]
final class AlertThresholdTest extends TestCase
{
    // ── AlertThreshold construction ────────────────────────────────

    #[Test]
    public function constructionStoresAllProperties(): void
    {
        $threshold = new AlertThreshold(
            metricName: 'http.latency_p99',
            value: 500.0,
            comparator: 'gt',
            forSeconds: 60,
            description: 'P99 latency exceeds 500ms',
        );

        self::assertSame('http.latency_p99', $threshold->metricName);
        self::assertSame(500.0, $threshold->value);
        self::assertSame('gt', $threshold->comparator);
        self::assertSame(60, $threshold->forSeconds);
        self::assertSame('P99 latency exceeds 500ms', $threshold->description);
    }

    #[Test]
    public function descriptionDefaultsToEmptyString(): void
    {
        $threshold = new AlertThreshold(
            metricName: 'cpu.usage',
            value: 90.0,
            comparator: 'gte',
            forSeconds: 300,
        );

        self::assertSame('', $threshold->description);
    }

    // ── isSatisfiedBy — comparator behavior ───────────────────────

    #[Test]
    #[DataProvider('greaterThanProvider')]
    public function greaterThanComparator(float $metric, float $threshold, bool $expected): void
    {
        $t = new AlertThreshold('m', $threshold, 'gt', 0);
        self::assertSame($expected, $t->isSatisfiedBy($metric));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function greaterThanProvider(): iterable
    {
        yield 'above' => [100.1, 100.0, true];
        yield 'equal' => [100.0, 100.0, false];
        yield 'below' => [99.9, 100.0, false];
    }

    #[Test]
    #[DataProvider('greaterThanOrEqualProvider')]
    public function greaterThanOrEqualComparator(float $metric, float $threshold, bool $expected): void
    {
        $t = new AlertThreshold('m', $threshold, 'gte', 0);
        self::assertSame($expected, $t->isSatisfiedBy($metric));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function greaterThanOrEqualProvider(): iterable
    {
        yield 'above' => [100.1, 100.0, true];
        yield 'equal' => [100.0, 100.0, true];
        yield 'below' => [99.9, 100.0, false];
    }

    #[Test]
    #[DataProvider('lessThanProvider')]
    public function lessThanComparator(float $metric, float $threshold, bool $expected): void
    {
        $t = new AlertThreshold('m', $threshold, 'lt', 0);
        self::assertSame($expected, $t->isSatisfiedBy($metric));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function lessThanProvider(): iterable
    {
        yield 'below' => [99.9, 100.0, true];
        yield 'equal' => [100.0, 100.0, false];
        yield 'above' => [100.1, 100.0, false];
    }

    #[Test]
    #[DataProvider('lessThanOrEqualProvider')]
    public function lessThanOrEqualComparator(float $metric, float $threshold, bool $expected): void
    {
        $t = new AlertThreshold('m', $threshold, 'lte', 0);
        self::assertSame($expected, $t->isSatisfiedBy($metric));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function lessThanOrEqualProvider(): iterable
    {
        yield 'below' => [99.9, 100.0, true];
        yield 'equal' => [100.0, 100.0, true];
        yield 'above' => [100.1, 100.0, false];
    }

    #[Test]
    #[DataProvider('equalProvider')]
    public function equalComparator(float $metric, float $threshold, bool $expected): void
    {
        $t = new AlertThreshold('m', $threshold, 'eq', 0);
        self::assertSame($expected, $t->isSatisfiedBy($metric));
    }

    /**
     * @return iterable<string, array{float, float, bool}>
     */
    public static function equalProvider(): iterable
    {
        yield 'exact match' => [42.0, 42.0, true];
        yield 'not equal' => [42.1, 42.0, false];
    }

    #[Test]
    public function unknownComparatorReturnsFalse(): void
    {
        $t = new AlertThreshold('m', 100.0, 'invalid', 0);
        self::assertFalse($t->isSatisfiedBy(100.0));
    }

    // ── AlertFiring ───────────────────────────────────────────────

    #[Test]
    public function firingConstructionStoresAllProperties(): void
    {
        $threshold = new AlertThreshold('cpu', 90.0, 'gt', 60);
        $firing = new AlertFiring(
            threshold: $threshold,
            actualValue: 95.5,
            firedAt: 1700000000.123,
        );

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(95.5, $firing->actualValue);
        self::assertSame(1700000000.123, $firing->firedAt);
    }

    #[Test]
    public function fromFactoryRecordsCurrentTimestamp(): void
    {
        $threshold = new AlertThreshold('mem.used', 80.0, 'gte', 120);
        $before = microtime(true);
        $firing = AlertFiring::from($threshold, 85.0);
        $after = microtime(true);

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(85.0, $firing->actualValue);
        self::assertGreaterThanOrEqual($before, $firing->firedAt);
        self::assertLessThanOrEqual($after, $firing->firedAt);
    }
}
