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
 * Handles GET /studio/console/benchmarks — the benchmark dashboard page.
 */
#[Internal]
final readonly class BenchmarkController
{
    public function __construct(
        private DashboardAggregator $aggregator,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(Request $_request): Response
    {
        $runs = $this->aggregator->benchmarkRuns(50);

        $latestRunId = $runs !== [] ? $runs[0]['run_id'] : null;
        $latestProfiles = $latestRunId !== null
            ? $this->aggregator->benchmarkProfiles($latestRunId)
            : [];

        $data = json_encode([
            'runs' => $runs,
            'latest_profiles' => $latestProfiles,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $safePayload = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Benchmarks - Pulsar Studio</title>
                <link rel="stylesheet" href="/studio/assets/studio.css">
            </head>
            <body>
                <div id="app" data-page="benchmark-dashboard" data-payload="$safePayload"></div>
                <script type="module" src="/studio/assets/main.js"></script>
            </body>
            </html>
            HTML;

        return Response::html($html);
    }
}
