<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\JsonLines\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\JsonLines\Schema\MetricSchema;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(MetricSchema::class)]
final class MetricSchemaTest extends TestCase
{
    #[Test]
    public function toArrayIncludesSchemaVersion(): void
    {
        $snapshot = new MetricSnapshot(
            name: 'http_requests_total',
            type: MetricType::Counter,
            help: 'Total HTTP requests',
            values: ['GET:/api' => 42.0],
        );

        $data = MetricSchema::toArray($snapshot);

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertSame('http_requests_total', $data['name']);
        self::assertSame('counter', $data['type']);
        self::assertSame('Total HTTP requests', $data['help']);
        self::assertSame(['GET:/api' => 42.0], $data['values']);
        self::assertArrayNotHasKey('series', $data);
    }

    #[Test]
    public function toArrayIncludesSeriesWhenPresent(): void
    {
        $snapshot = new MetricSnapshot(
            name: 'request_duration',
            type: MetricType::Histogram,
            help: 'Request duration in seconds',
            values: [],
            series: ['default' => ['buckets' => [50 => 10, 100 => 5], 'sum' => 150.0, 'count' => 15]],
        );

        $data = MetricSchema::toArray($snapshot);

        self::assertArrayHasKey('series', $data);
        self::assertSame(['default' => ['buckets' => [50 => 10, 100 => 5], 'sum' => 150.0, 'count' => 15]], $data['series']);
    }

    #[Test]
    public function toJsonProducesValidJson(): void
    {
        $snapshot = new MetricSnapshot(
            name: 'gauge_metric',
            type: MetricType::Gauge,
            help: 'A gauge',
            values: ['label' => 3.14],
        );

        $json = MetricSchema::toJson($snapshot);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('gauge_metric', $decoded['name']);
        self::assertSame('gauge', $decoded['type']);
        self::assertStringNotContainsString('\\/', $json);
    }
}
