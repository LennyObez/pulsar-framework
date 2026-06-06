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
 * Handles GET /studio/console/benchmarks: the benchmark dashboard page.
 */
#[Internal]
final readonly class BenchmarkController
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
    public function handle(ServerRequestInterface $_request): Response
    {
        $runs = $this->aggregator->benchmarkRuns(50);

        $latestRunId = $runs !== [] ? $runs[0]['run_id'] : null;
        $latestProfiles = $latestRunId !== null
            ? $this->aggregator->benchmarkProfiles($latestRunId)
            : [];

        $dataJson = json_encode([
            'runs' => $runs,
            'latest_profiles' => $latestProfiles,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Benchmarks - Pulsar Studio', 'console/benchmarks', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }
}
