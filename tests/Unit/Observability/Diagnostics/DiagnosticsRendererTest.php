<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use RuntimeException;

#[CoversClass(DiagnosticsRenderer::class)]
final class DiagnosticsRendererTest extends TestCase
{
    #[Test]
    public function renderReturnsHtmlWithAllSections(): void
    {
        $registry = new MetricRegistry();
        $renderer = new DiagnosticsRenderer($registry);

        $html = $renderer->render();

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

        self::assertStringContainsString('No metrics recorded yet.', $html);
        self::assertStringContainsString('No errors tracked yet.', $html);
        self::assertStringContainsString('No traces collected yet.', $html);
    }

    #[Test]
    public function renderShowsCounterMetric(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment();

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('http_requests_total', $html);
        self::assertStringContainsString('counter', $html);
    }

    #[Test]
    public function renderShowsGaugeMetric(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('memory_usage', 'Current memory');
        $gauge->set(42.5);

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('memory_usage', $html);
        self::assertStringContainsString('gauge', $html);
    }

    #[Test]
    public function renderShowsHistogramMetric(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('response_time', 'Response time', [0.1, 0.5, 1.0]);
        $histogram->observe(0.25);

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('response_time', $html);
        self::assertStringContainsString('histogram', $html);
    }

    #[Test]
    public function renderShowsErrorGroups(): void
    {
        $registry = new MetricRegistry();
        $aggregator = new ErrorAggregator();
        $aggregator->capture(ErrorEvent::fromThrowable(new RuntimeException('Test failure')));

        $renderer = new DiagnosticsRenderer($registry, aggregator: $aggregator);
        $html = $renderer->render();

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('Test failure', $html);
    }

    #[Test]
    public function renderShowsTraces(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $span = new Span(
            name: 'test.operation',
            context: new TraceContext(
                traceId: new TraceId('abcdef1234567890abcdef1234567890'),
                spanId: new SpanId('1234567890abcdef'),
            ),
        );
        $span->end();
        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('test.operation', $html);
        self::assertStringContainsString('abcdef12...', $html);
    }

    #[Test]
    public function renderShowsSpanWithErrorStatus(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $span = new Span(
            name: 'failing.op',
            context: new TraceContext(
                traceId: new TraceId('abcdef1234567890abcdef1234567890'),
                spanId: new SpanId('1234567890abcdef'),
            ),
        );
        $span->status = SpanStatus::Error;
        $span->end();
        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('badge-error', $html);
    }

    #[Test]
    public function renderShowsSpanWithOkStatus(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $span = new Span(
            name: 'ok.op',
            context: new TraceContext(
                traceId: new TraceId('abcdef1234567890abcdef1234567890'),
                spanId: new SpanId('1234567890abcdef'),
            ),
        );
        $span->status = SpanStatus::Ok;
        $span->end();
        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('badge-ok', $html);
    }

    #[Test]
    public function renderShowsRunningSpanDuration(): void
    {
        $registry = new MetricRegistry();
        $collector = new InMemorySpanCollector();

        $span = new Span(
            name: 'running.op',
            context: new TraceContext(
                traceId: new TraceId('abcdef1234567890abcdef1234567890'),
                spanId: new SpanId('1234567890abcdef'),
            ),
        );
        // Don't call end() — the span is still running
        $collector->onEnd($span);

        $renderer = new DiagnosticsRenderer($registry, collector: $collector);
        $html = $renderer->render();

        self::assertStringContainsString('running', $html);
    }

    #[Test]
    public function renderEscapesHtmlSpecialChars(): void
    {
        $registry = new MetricRegistry();
        $aggregator = new ErrorAggregator();
        $aggregator->capture(ErrorEvent::fromThrowable(new RuntimeException('<script>alert("xss")</script>')));

        $renderer = new DiagnosticsRenderer($registry, aggregator: $aggregator);
        $html = $renderer->render();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderShowsCounterWithLabelledValues(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment(new LabelSet(['method' => 'GET']));
        $counter->increment(new LabelSet(['method' => 'POST']));

        $renderer = new DiagnosticsRenderer($registry);
        $html = $renderer->render();

        self::assertStringContainsString('http_requests_total', $html);
    }
}
