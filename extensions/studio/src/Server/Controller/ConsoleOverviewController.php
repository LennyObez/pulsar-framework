<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Http\Message\Response;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console: the Console overview dashboard.
 */
#[Internal]
final readonly class ConsoleOverviewController
{
    use RendersStudioView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private DashboardAggregator $aggregator,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $request): Response
    {
        $windowUs = $this->parseWindow($request);

        $sections = $this->aggregator->availableSections();
        $throughput = $this->aggregator->throughput($windowUs);
        $latency = $this->aggregator->latencyPercentiles($windowUs);
        $errorRate = $this->aggregator->errorRate($windowUs);
        $slowRoutes = $this->aggregator->slowRoutes($windowUs);
        $slowQueries = $this->aggregator->slowQueries($windowUs);
        $eventCounts = $this->aggregator->eventCountsByType($windowUs);

        $dataJson = json_encode([
            'sections' => $sections,
            'throughput' => $throughput,
            'latency' => $latency,
            'error_rate' => $errorRate,
            'slow_routes' => $slowRoutes,
            'slow_queries' => $slowQueries,
            'event_counts' => $eventCounts,
            'throughput_series' => $this->aggregator->throughputTimeSeries($windowUs),
            'error_series' => $this->aggregator->errorTimeSeries($windowUs),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Console overview - Pulsar Studio', 'console/overview', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }

    private function parseWindow(ServerRequestInterface $request): int
    {
        /** @var mixed $window */
        $window = $request->getAttribute('_query_window');

        return match ($window) {
            '5m' => 5 * 60 * 1_000_000,
            '1h' => 60 * 60 * 1_000_000,
            default => 24 * 60 * 60 * 1_000_000,
        };
    }
}
