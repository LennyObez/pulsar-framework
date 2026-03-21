<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Api;

use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function hrtime;
use function usort;

/**
 * Collects timing data for template renders.
 *
 * Tracks render duration per template name. Results are available for
 * Studio dashboard display and performance analysis.
 * @api
 */
#[Api(since: '1.0.0')]
final class TemplateProfiler
{
    /** @var array<string, list<float>> Template name => list of durations in milliseconds */
    private array $timings = [];

    /** @var array<string, list<string>> Template name => list of dependency template names */
    private array $dependencies = [];

    /**
     * Record the start of a template render and return a callback to stop timing.
     *
     * @return callable(): void Stop callback: call when rendering is complete
     */
    public function start(string $templateName): callable
    {
        $startNs = hrtime(true);

        return function () use ($templateName, $startNs): void {
            $durationMs = (hrtime(true) - $startNs) / 1_000_000;

            if (!isset($this->timings[$templateName])) {
                $this->timings[$templateName] = [];
            }

            $this->timings[$templateName][] = $durationMs;
        };
    }

    /**
     * Record a template dependency (e.g., from @extends, @include, @component).
     */
    public function recordDependency(string $parentTemplate, string $childTemplate): void
    {
        if (!isset($this->dependencies[$parentTemplate])) {
            $this->dependencies[$parentTemplate] = [];
        }

        $this->dependencies[$parentTemplate][] = $childTemplate;
    }

    /**
     * Get timing data for all profiled templates.
     *
     * @return array<string, array{count: int, total_ms: float, avg_ms: float, min_ms: float, max_ms: float}>
     */
    #[NoDiscard]
    public function timings(): array
    {
        $result = [];

        foreach ($this->timings as $name => $durations) {
            $total = array_sum($durations);
            $count = count($durations);
            $sorted = $durations;
            usort($sorted, static fn(float $a, float $b): int => $a <=> $b);

            $result[$name] = [
                'count' => $count,
                'total_ms' => $total,
                'avg_ms' => $count > 0 ? $total / $count : 0.0,
                'min_ms' => $sorted[0] ?? 0.0,
                'max_ms' => $sorted[count($sorted) - 1] ?? 0.0,
            ];
        }

        return $result;
    }

    /**
     * Get the dependency graph for all profiled templates.
     *
     * @return array<string, list<string>>
     */
    #[NoDiscard]
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Get the slowest templates.
     *
     * @param int $limit Maximum number of results
     *
     * @return list<array{name: string, total_ms: float, count: int, avg_ms: float}>
     */
    #[NoDiscard]
    public function slowest(int $limit = 10): array
    {
        $timings = $this->timings();
        $items = [];

        foreach ($timings as $name => $data) {
            $items[] = [
                'name' => $name,
                'total_ms' => $data['total_ms'],
                'count' => $data['count'],
                'avg_ms' => $data['avg_ms'],
            ];
        }

        usort($items, static fn(array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return array_values(array_slice($items, 0, $limit));
    }

    /**
     * Reset all profiling data.
     */
    public function reset(): void
    {
        $this->timings = [];
        $this->dependencies = [];
    }
}
