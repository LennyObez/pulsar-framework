<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function htmlspecialchars;
use function json_encode;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console — the Console overview dashboard.
 */
#[Internal]
final readonly class ConsoleOverviewController
{
    public function __construct(
        private DashboardAggregator $aggregator,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(Request $request): Response
    {
        $windowUs = $this->parseWindow($request);

        $sections = $this->aggregator->availableSections();
        $throughput = $this->aggregator->throughput($windowUs);
        $latency = $this->aggregator->latencyPercentiles($windowUs);
        $errorRate = $this->aggregator->errorRate($windowUs);
        $slowRoutes = $this->aggregator->slowRoutes($windowUs);
        $slowQueries = $this->aggregator->slowQueries($windowUs);
        $eventCounts = $this->aggregator->eventCountsByType($windowUs);

        $data = json_encode([
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

        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Console Overview - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="console-overview" data-payload="$safePayload"></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }

    private function parseWindow(Request $request): int
    {
        $window = $request->attribute('_query_window');

        return match ($window) {
            '5m' => 5 * 60 * 1_000_000,
            '1h' => 60 * 60 * 1_000_000,
            default => 24 * 60 * 60 * 1_000_000,
        };
    }
}
