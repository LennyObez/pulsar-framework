<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

#[CoversClass(DiagnosticsRenderer::class)]
final class DiagnosticsRendererCoverageTest extends TestCase
{
    #[Test]
    public function renderProducesValidHtml(): void
    {
        $registry = new MetricRegistry();
        $renderer = new DiagnosticsRenderer($registry);

        $html = $renderer->render();

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Pulsar Diagnostics', $html);
        self::assertStringContainsString('Metrics', $html);
        self::assertStringContainsString('Error Groups', $html);
        self::assertStringContainsString('Recent Traces', $html);
    }

    #[Test]
    public function renderShowsEmptyStatesWhenNoData(): void
    {
        $registry = new MetricRegistry();
        $renderer = new DiagnosticsRenderer($registry);

        $html = $renderer->render();

        self::assertStringContainsString('No metrics recorded yet', $html);
        self::assertStringContainsString('No errors tracked yet', $html);
        self::assertStringContainsString('No traces collected yet', $html);
    }

    #[Test]
    public function renderDisplaysCounterMetrics(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment();
        $counter->increment();
        $counter->increment();

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('http_requests_total', $html);
        self::assertStringContainsString('counter', $html);
    }

    #[Test]
    public function renderDisplaysGaugeMetrics(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('active_connections', 'Active connections');
        $gauge->set(42.0);

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('active_connections', $html);
        self::assertStringContainsString('gauge', $html);
    }

    #[Test]
    public function renderDisplaysHistogramMetrics(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('request_duration', 'Duration', [10, 50, 100, 500]);
        $histogram->observe(25.0);

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('request_duration', $html);
        self::assertStringContainsString('histogram', $html);
    }

    #[Test]
    public function renderDisplaysErrorGroups(): void
    {
        $registry = new MetricRegistry();
        $aggregator = new ErrorAggregator();

        $event = \Pulsar\Observability\ErrorTracking\ErrorEvent::fromThrowable(new RuntimeException('Test error'));
        $aggregator->capture($event);

        $renderer = new DiagnosticsRenderer($registry, aggregator: $aggregator);
        $html = $renderer->render();

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('Test error', $html);
    }

    #[Test]
    public function renderDisplaysTraces(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $traceId = TraceId::generate();
        $spanId = SpanId::generate();
        $context = new TraceContext($traceId, $spanId);

        $span = new Span(
            name: 'test-span',
            context: $context,
        );
        $span->status = SpanStatus::Ok;
        $span->end();

        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('test-span', $html);
        self::assertStringContainsString('ok', $html);
    }

    #[Test]
    public function renderEscapesHtmlInMetricNames(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('metric_<script>', 'Test');
        $counter->increment();

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function renderWithNullCollectorAndAggregator(): void
    {
        $registry = new MetricRegistry();
        $renderer = new DiagnosticsRenderer($registry, collector: null, aggregator: null);

        $html = $renderer->render();

        self::assertStringContainsString('No errors tracked yet', $html);
        self::assertStringContainsString('No traces collected yet', $html);
    }

    #[Test]
    public function renderShowsErrorSpanStatus(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $span = new Span(
            name: 'error-span',
            context: new TraceContext(TraceId::generate(), SpanId::generate()),
        );
        $span->status = SpanStatus::Error;
        $span->end();

        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('error-span', $html);
        self::assertStringContainsString('badge-error', $html);
    }
}
