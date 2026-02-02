<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\PrometheusExporter;

#[CoversClass(PrometheusExporter::class)]
final class PrometheusExporterTest extends TestCase
{
    #[Test]
    public function exportsCounterWithHelpAndType(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment();

        $exporter = new PrometheusExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# HELP http_requests_total Total HTTP requests', $output);
        self::assertStringContainsString('# TYPE http_requests_total counter', $output);
        self::assertStringContainsString('http_requests_total 1', $output);
    }

    #[Test]
    public function exportsCounterWithLabels(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('requests');
        $counter->increment(new LabelSet(['method' => 'GET', 'status' => '200']));

        $exporter = new PrometheusExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('method="GET"', $output);
        self::assertStringContainsString('status="200"', $output);
    }

    #[Test]
    public function exportsGauge(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('temperature', 'Current temperature');
        $gauge->set(36.6);

        $exporter = new PrometheusExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE temperature gauge', $output);
        self::assertStringContainsString('temperature 36.6', $output);
    }

    #[Test]
    public function exportsHistogramWithBuckets(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('duration', 'Request duration', [0.1, 0.5, 1.0]);
        $histogram->observe(0.3);

        $exporter = new PrometheusExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE duration histogram', $output);
        self::assertStringContainsString('duration_bucket{le="0.1"} 0', $output);
        self::assertStringContainsString('duration_bucket{le="0.5"} 1', $output);
        self::assertStringContainsString('duration_bucket{le="1"} 1', $output);
        self::assertStringContainsString('duration_bucket{le="+Inf"} 1', $output);
        self::assertStringContainsString('duration_sum', $output);
        self::assertStringContainsString('duration_count 1', $output);
    }

    #[Test]
    public function exportsEmptyRegistry(): void
    {
        $registry = new MetricRegistry();
        $exporter = new PrometheusExporter($registry);

        self::assertSame("\n", $exporter->export());
    }
}
