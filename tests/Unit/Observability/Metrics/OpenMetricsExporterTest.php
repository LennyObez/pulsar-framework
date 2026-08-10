<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\OpenMetricsExporter;

#[CoversClass(OpenMetricsExporter::class)]
final class OpenMetricsExporterTest extends TestCase
{
    #[Test]
    public function exportsCounterWithHelpAndType(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment();

        $exporter = new OpenMetricsExporter($registry);
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

        $exporter = new OpenMetricsExporter($registry);
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

        $exporter = new OpenMetricsExporter($registry);
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

        $exporter = new OpenMetricsExporter($registry);
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
        $exporter = new OpenMetricsExporter($registry);

        self::assertSame("\n", $exporter->export());
    }

    #[Test]
    public function exportsHistogramWithLabels(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('http_duration', 'Duration', [0.1, 0.5]);
        $histogram->observe(0.3, new LabelSet(['method' => 'GET']));

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE http_duration histogram', $output);
        self::assertStringContainsString('http_duration_bucket', $output);
        self::assertStringContainsString('method="GET"', $output);
        self::assertStringContainsString('le="0.1"', $output);
        self::assertStringContainsString('le="+Inf"', $output);
        self::assertStringContainsString('http_duration_sum', $output);
        self::assertStringContainsString('http_duration_count', $output);
    }

    #[Test]
    public function escapesSpecialCharactersInHelpText(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('test_counter', "Help with \\backslash and \nnewline");

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('\\\\', $output);
        self::assertStringContainsString('\\n', $output);
    }

    #[Test]
    public function escapesSpecialCharactersInLabelValues(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('errors');
        $counter->increment(new LabelSet(['message' => 'value with "quotes" and \\backslash']));

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('\\"', $output);
        self::assertStringContainsString('\\\\', $output);
    }

    #[Test]
    public function exportsGaugeWithNegativeValue(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('balance', 'Account balance');
        $gauge->set(-42.5);

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE balance gauge', $output);
        self::assertStringContainsString('balance -42.5', $output);
    }

    #[Test]
    public function exportsCounterWithZeroValueWhenNoIncrements(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('idle_counter', 'Never incremented');

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE idle_counter counter', $output);
        self::assertStringContainsString('idle_counter 0', $output);
    }

    #[Test]
    public function exportsEmptyHistogramWithBucketBoundaries(): void
    {
        $registry = new MetricRegistry();
        $registry->histogram('empty_hist', 'No observations', [0.1, 0.5, 1.0]);

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('empty_hist_bucket{le="0.1"} 0', $output);
        self::assertStringContainsString('empty_hist_bucket{le="0.5"} 0', $output);
        self::assertStringContainsString('empty_hist_bucket{le="1"} 0', $output);
        self::assertStringContainsString('empty_hist_bucket{le="+Inf"} 0', $output);
        self::assertStringContainsString('empty_hist_sum 0', $output);
        self::assertStringContainsString('empty_hist_count 0', $output);
    }

    #[Test]
    public function exportsMultipleMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('counter_a', 'First counter');
        $registry->gauge('gauge_b', 'A gauge');
        $registry->histogram('hist_c', 'A histogram', [1.0]);

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE counter_a counter', $output);
        self::assertStringContainsString('# TYPE gauge_b gauge', $output);
        self::assertStringContainsString('# TYPE hist_c histogram', $output);
    }

    #[Test]
    public function exportsGaugeWithZeroValueWhenNotSet(): void
    {
        $registry = new MetricRegistry();
        $registry->gauge('unset_gauge');

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('# TYPE unset_gauge gauge', $output);
        self::assertStringContainsString('unset_gauge 0', $output);
    }

    #[Test]
    public function exportsCounterLabelValueContainingCommaWithoutCorruption(): void
    {
        // A user-supplied label value may contain a literal comma (e.g. a RUM
        // `url`). Splitting a serialised label set on commas would truncate the
        // value and emit an orphaned label pair, so the exporter must not.
        $registry = new MetricRegistry();
        $counter = $registry->counter('rum_navigations');
        $counter->increment(new LabelSet(['url' => 'https://example.com/a,b', 'method' => 'GET']));

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        // The comma survives intact inside the exported (quoted) label value,
        // and no orphaned label is produced.
        self::assertStringContainsString('url="https://example.com/a,b"', $output);
        self::assertStringContainsString('method="GET"', $output);
        self::assertStringNotContainsString('%2C', $output);
    }

    #[Test]
    public function exportsHistogramLabelValueContainingCommaWithoutCorruption(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('rum_lcp', 'LCP', [0.1, 0.5]);
        $histogram->observe(0.3, new LabelSet(['url' => '/list?a=1,2,3']));

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringContainsString('url="/list?a=1,2,3"', $output);
        self::assertStringNotContainsString('%2C', $output);
    }

    #[Test]
    public function counterWithoutHelpOmitsHelpLine(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('no_help_counter');
        $counter->increment();

        $exporter = new OpenMetricsExporter($registry);
        $output = $exporter->export();

        self::assertStringNotContainsString('# HELP no_help_counter', $output);
        self::assertStringContainsString('# TYPE no_help_counter counter', $output);
    }
}
