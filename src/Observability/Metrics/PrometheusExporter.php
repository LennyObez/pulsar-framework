<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use function implode;
use function sprintf;

/**
 * Renders a MetricRegistry as Prometheus text exposition format 0.0.4.
 *
 * Output includes # HELP, # TYPE lines, and sample lines with labels.
 * Histograms render _bucket, _sum, and _count series.
 */
final readonly class PrometheusExporter
{
    public function __construct(
        private MetricRegistry $registry,
    ) {}

    /**
     * Render all metrics as Prometheus exposition text.
     */
    public function export(): string
    {
        $lines = [];

        foreach ($this->registry->all() as $name => $metric) {
            if ($metric instanceof Counter) {
                $this->renderCounter($lines, $name, $metric);
            } elseif ($metric instanceof Gauge) {
                $this->renderGauge($lines, $name, $metric);
            } elseif ($metric instanceof Histogram) {
                $this->renderHistogram($lines, $name, $metric);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    private function renderCounter(array &$lines, string $name, Counter $counter): void
    {
        if ($counter->help !== '') {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($counter->help));
        }

        $lines[] = sprintf('# TYPE %s counter', $name);

        foreach ($counter->values() as $labelKey => $value) {
            $lines[] = $this->formatSample($name, $labelKey, $value);
        }

        // Emit a zero-value line if no values recorded
        if ($counter->values() === []) {
            $lines[] = sprintf('%s 0', $name);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function renderGauge(array &$lines, string $name, Gauge $gauge): void
    {
        if ($gauge->help !== '') {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($gauge->help));
        }

        $lines[] = sprintf('# TYPE %s gauge', $name);

        foreach ($gauge->values() as $labelKey => $value) {
            $lines[] = $this->formatSample($name, $labelKey, $value);
        }

        if ($gauge->values() === []) {
            $lines[] = sprintf('%s 0', $name);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function renderHistogram(array &$lines, string $name, Histogram $histogram): void
    {
        if ($histogram->help !== '') {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($histogram->help));
        }

        $lines[] = sprintf('# TYPE %s histogram', $name);

        foreach ($histogram->seriesKeys() as $seriesKey) {
            $labels = $this->parseLabelString($seriesKey);
            $labelSet = new LabelSet($labels);
            $buckets = $histogram->buckets($labelSet);

            foreach ($buckets as $bound => $count) {
                $bucketLabels = $labels;
                $bucketLabels['le'] = (string) $bound;
                $lines[] = $this->formatSample($name . '_bucket', $this->formatLabels($bucketLabels), (float) $count);
            }

            // +Inf bucket
            $infLabels = $labels;
            $infLabels['le'] = '+Inf';
            $lines[] = $this->formatSample($name . '_bucket', $this->formatLabels($infLabels), (float) $histogram->count($labelSet));

            $lines[] = $this->formatSample($name . '_sum', $seriesKey, $histogram->sum($labelSet));
            $lines[] = $this->formatSample($name . '_count', $seriesKey, (float) $histogram->count($labelSet));
        }

        // Emit empty histogram if no series
        if ($histogram->seriesKeys() === []) {
            foreach ($histogram->boundaries() as $bound) {
                $lines[] = sprintf('%s_bucket{le="%s"} 0', $name, $bound);
            }

            $lines[] = sprintf('%s_bucket{le="+Inf"} 0', $name);
            $lines[] = sprintf('%s_sum 0', $name);
            $lines[] = sprintf('%s_count 0', $name);
        }
    }

    private function formatSample(string $name, string $labelKey, float $value): string
    {
        $formatted = $this->formatFloat($value);

        if ($labelKey === '') {
            return sprintf('%s %s', $name, $formatted);
        }

        // Check if labelKey is already formatted as {key="val",...}
        if (str_starts_with($labelKey, '{')) {
            return sprintf('%s%s %s', $name, $labelKey, $formatted);
        }

        // Convert key=value,key2=value2 to {key="value",key2="value2"}
        $labels = $this->parseLabelString($labelKey);

        return sprintf('%s%s %s', $name, $this->formatLabels($labels), $formatted);
    }

    /**
     * @param array<string, string|int|float> $labels
     */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];

        foreach ($labels as $k => $v) {
            $parts[] = sprintf('%s="%s"', $k, $this->escapeLabelValue((string) $v));
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Parse "key=value,key2=value2" into an array.
     *
     * @return array<string, string>
     */
    private function parseLabelString(string $labelKey): array
    {
        if ($labelKey === '') {
            return [];
        }

        $result = [];

        foreach (explode(',', $labelKey) as $pair) {
            $parts = explode('=', $pair, 2);

            if (isset($parts[1])) {
                $result[$parts[0]] = $parts[1];
            }
        }

        return $result;
    }

    private function formatFloat(float $value): string
    {
        if ($value === (float) (int) $value) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    private function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
