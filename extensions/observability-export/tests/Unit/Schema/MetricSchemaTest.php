<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\MetricSchema;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

final class MetricSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $snapshot = $this->createSnapshot();
        $array = MetricSchema::toArray($snapshot);

        self::assertSame('1.0.0', $array['schema_version']);
    }

    #[Test]
    public function toArrayIncludesBasicFields(): void
    {
        $snapshot = $this->createSnapshot();
        $array = MetricSchema::toArray($snapshot);

        self::assertSame('http_requests_total', $array['name']);
        self::assertSame('counter', $array['type']);
        self::assertSame('Total HTTP requests', $array['help']);
        self::assertSame(['total' => 42.0], $array['values']);
    }

    #[Test]
    public function toArrayExcludesSeriesWhenNull(): void
    {
        $snapshot = $this->createSnapshot(series: null);
        $array = MetricSchema::toArray($snapshot);

        self::assertArrayNotHasKey('series', $array);
    }

    #[Test]
    public function toArrayIncludesSeriesWhenPresent(): void
    {
        $series = ['default' => ['buckets' => [100 => 5, 200 => 10], 'sum' => 150.0, 'count' => 15]];
        $snapshot = $this->createSnapshot(series: $series);
        $array = MetricSchema::toArray($snapshot);

        self::assertArrayHasKey('series', $array);
        self::assertSame($series, $array['series']);
    }

    #[Test]
    public function toJsonReturnsValidJson(): void
    {
        $snapshot = $this->createSnapshot();
        $json = MetricSchema::toJson($snapshot);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http_requests_total', $decoded['name']);
    }

    /**
     * @param array<string, array{buckets: array<int|string, int>, sum: float, count: int}>|null $series
     */
    private function createSnapshot(?array $series = null): MetricSnapshot
    {
        return new MetricSnapshot(
            name: 'http_requests_total',
            type: MetricType::Counter,
            help: 'Total HTTP requests',
            values: ['total' => 42.0],
            series: $series,
        );
    }
}
