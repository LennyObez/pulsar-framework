<?php

declare(strict_types=1);

namespace Pulsar\Observability\Diagnostics;

use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorGroup;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanStatus;

use function array_slice;
use function htmlspecialchars;
use function sprintf;

/**
 * Renders a local diagnostics HTML page (debug mode only).
 *
 * Displays metrics, error groups, and recent traces in a dark-themed
 * HTML5 page with inline CSS, matching DevelopmentRenderer style.
 */
final readonly class DiagnosticsRenderer
{
    public function __construct(
        private MetricRegistry $registry,
        private ?InMemorySpanCollector $collector = null,
        private ?ErrorAggregator $aggregator = null,
    ) {}

    public function render(): string
    {
        $metricsHtml = $this->renderMetrics();
        $errorsHtml = $this->renderErrors();
        $tracesHtml = $this->renderTraces();

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Pulsar Diagnostics</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: system-ui, -apple-system, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 2rem; }
                    .container { max-width: 1100px; margin: 0 auto; }
                    .header { background: #0f3460; color: #fff; padding: 1.5rem; border-radius: 8px 8px 0 0; }
                    .header h1 { font-size: 1.25rem; font-weight: 600; }
                    .header .sub { font-size: 0.875rem; opacity: 0.9; margin-top: 0.25rem; }
                    .section { background: #16213e; padding: 1.5rem; border-bottom: 1px solid #0f3460; }
                    .section:last-child { border-radius: 0 0 8px 8px; border-bottom: none; }
                    .section h2 { font-size: 1rem; color: #e94560; margin-bottom: 1rem; }
                    table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
                    th { text-align: left; padding: 0.5rem 0.75rem; background: #0f3460; color: #e94560; font-weight: 600; }
                    td { padding: 0.375rem 0.75rem; border-bottom: 1px solid #0f3460; vertical-align: top; }
                    .mono { font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.75rem; }
                    .badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 4px; font-size: 0.6875rem; font-weight: 600; }
                    .badge-counter { background: #1abc9c; color: #fff; }
                    .badge-gauge { background: #3498db; color: #fff; }
                    .badge-histogram { background: #9b59b6; color: #fff; }
                    .badge-error { background: #c0392b; color: #fff; }
                    .badge-ok { background: #27ae60; color: #fff; }
                    .empty { color: #666; font-style: italic; padding: 1rem; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Pulsar Diagnostics</h1>
                        <div class="sub">Debug-mode observability dashboard</div>
                    </div>

                    <div class="section">
                        <h2>Metrics</h2>
                        $metricsHtml
                    </div>

                    <div class="section">
                        <h2>Error Groups</h2>
                        $errorsHtml
                    </div>

                    <div class="section">
                        <h2>Recent Traces</h2>
                        $tracesHtml
                    </div>
                </div>
            </body>
            </html>
            HTML;
    }

    private function renderMetrics(): string
    {
        $metrics = $this->registry->all();

        if ($metrics === []) {
            return '<p class="empty">No metrics recorded yet.</p>';
        }

        $html = '<table><tr><th>Name</th><th>Type</th><th>Values</th></tr>';

        foreach ($metrics as $name => $metric) {
            $escapedName = $this->escape($name);

            if ($metric instanceof Counter) {
                $badge = '<span class="badge badge-counter">counter</span>';
                $values = $this->formatValues($metric->values());
            } elseif ($metric instanceof Gauge) {
                $badge = '<span class="badge badge-gauge">gauge</span>';
                $values = $this->formatValues($metric->values());
            } elseif ($metric instanceof Histogram) {
                $badge = '<span class="badge badge-histogram">histogram</span>';
                $values = sprintf('series: %d', $metric->seriesCount());
            } else {
                continue;
            }

            $html .= sprintf(
                '<tr><td class="mono">%s</td><td>%s</td><td class="mono">%s</td></tr>',
                $escapedName,
                $badge,
                $this->escape($values),
            );
        }

        $html .= '</table>';

        return $html;
    }

    private function renderErrors(): string
    {
        if ($this->aggregator === null || $this->aggregator->count() === 0) {
            return '<p class="empty">No errors tracked yet.</p>';
        }

        $html = '<table><tr><th>Exception</th><th>Message</th><th>Count</th><th>Last Seen</th></tr>';

        foreach ($this->aggregator->groups() as $group) {
            $html .= $this->renderErrorGroup($group);
        }

        $html .= '</table>';

        return $html;
    }

    private function renderErrorGroup(ErrorGroup $group): string
    {
        return sprintf(
            '<tr><td class="mono">%s</td><td>%s</td><td><span class="badge badge-error">%d</span></td><td class="mono">%s</td></tr>',
            $this->escape($group->exceptionClass() ?? 'Unknown'),
            $this->escape($group->message() ?? ''),
            $group->occurrenceCount(),
            $this->escape($group->lastSeen()->format('Y-m-d H:i:s')),
        );
    }

    private function renderTraces(): string
    {
        if ($this->collector === null || $this->collector->count() === 0) {
            return '<p class="empty">No traces collected yet.</p>';
        }

        $spans = $this->collector->spans();
        $recentSpans = array_slice($spans, -50);
        $recentSpans = array_reverse($recentSpans);

        $html = '<table><tr><th>Span Name</th><th>Trace ID</th><th>Status</th><th>Duration</th></tr>';

        foreach ($recentSpans as $span) {
            $html .= $this->renderSpanRow($span);
        }

        $html .= '</table>';

        return $html;
    }

    private function renderSpanRow(Span $span): string
    {
        $duration = $span->durationSeconds();
        $durationStr = $duration !== null ? sprintf('%.4fs', $duration) : 'running';

        $statusBadge = match ($span->status) {
            SpanStatus::Ok => '<span class="badge badge-ok">ok</span>',
            SpanStatus::Error => '<span class="badge badge-error">error</span>',
            default => '<span class="badge">unset</span>',
        };

        return sprintf(
            '<tr><td class="mono">%s</td><td class="mono">%s</td><td>%s</td><td class="mono">%s</td></tr>',
            $this->escape($span->name),
            $this->escape(substr($span->context->traceId->value, 0, 8) . '...'),
            $statusBadge,
            $this->escape($durationStr),
        );
    }

    /**
     * @param array<string, float> $values
     */
    private function formatValues(array $values): string
    {
        if ($values === []) {
            return '(empty)';
        }

        $parts = [];

        foreach ($values as $key => $value) {
            if ($key === '') {
                $parts[] = (string) $value;
            } else {
                $parts[] = sprintf('{%s} %s', $key, $value);
            }
        }

        return implode(', ', $parts);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
