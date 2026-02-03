<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use function array_key_exists;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(Histogram::class)]
final class HistogramTest extends TestCase
{
    #[Test]
    public function observeRecordsValueInBuckets(): void
    {
        $histogram = new Histogram('duration', boundaries: [0.1, 0.5, 1.0]);
        $histogram->observe(0.3);

        self::assertSame(1, $histogram->count());
        self::assertEqualsWithDelta(0.3, $histogram->sum(), 0.0001);

        $buckets = $histogram->buckets();
        // 0.1 and 0.5 stay as string keys; 1.0 → '1' → int key 1 in PHP
        self::assertSame(0, $buckets['0.1']);
        self::assertSame(1, $buckets['0.5']);

        // PHP casts numeric-string '1' to int key 1
        $oneKey = array_key_exists(1, $buckets) ? 1 : '1';
        self::assertSame(1, $buckets[$oneKey]);
    }

    #[Test]
    public function observeRecordsInAllMatchingBuckets(): void
    {
        $histogram = new Histogram('size', boundaries: [10.0, 50.0, 100.0]);
        $histogram->observe(5.0);

        $buckets = $histogram->buckets();

        // All boundaries are integer-like floats, so PHP casts their string keys to int
        foreach ([10, 50, 100] as $intKey) {
            self::assertArrayHasKey($intKey, $buckets);
            self::assertSame(1, $buckets[$intKey]);
        }
    }

    #[Test]
    public function observeValueAboveAllBuckets(): void
    {
        $histogram = new Histogram('latency', boundaries: [0.1, 0.5]);
        $histogram->observe(999.0);

        $buckets = $histogram->buckets();
        self::assertSame(0, $buckets['0.1']);
        self::assertSame(0, $buckets['0.5']);
        self::assertSame(1, $histogram->count());
        self::assertEqualsWithDelta(999.0, $histogram->sum(), 0.0001);
    }

    #[Test]
    public function tracksSeriesByLabelSet(): void
    {
        $histogram = new Histogram('request_duration', boundaries: [0.1, 1.0]);
        $get = new LabelSet(['method' => 'GET']);
        $post = new LabelSet(['method' => 'POST']);

        $histogram->observe(0.05, $get);
        $histogram->observe(0.5, $post);

        self::assertSame(1, $histogram->count($get));
        self::assertSame(1, $histogram->count($post));
        self::assertEqualsWithDelta(0.05, $histogram->sum($get), 0.0001);
        self::assertEqualsWithDelta(0.5, $histogram->sum($post), 0.0001);
    }

    #[Test]
    public function usesDefaultBoundaries(): void
    {
        $histogram = new Histogram('default');

        self::assertSame(Histogram::DEFAULT_BOUNDARIES, $histogram->boundaries());
    }

    #[Test]
    public function resetClearsAllData(): void
    {
        $histogram = new Histogram('test', boundaries: [1.0]);
        $histogram->observe(0.5);
        $histogram->reset();

        self::assertSame(0, $histogram->count());
        self::assertSame(0.0, $histogram->sum());
        self::assertSame([], $histogram->seriesKeys());
    }
}
