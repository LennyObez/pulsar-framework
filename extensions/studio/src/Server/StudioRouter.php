<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server;

use JsonException;

use function preg_match;

use Pulsar\Api\Internal;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;
use Pulsar\Extension\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\Extension\Studio\Server\Controller\LogExplorerController;
use Pulsar\Extension\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;

/**
 * Standalone router for the Studio server.
 *
 * Matches incoming requests to Studio controllers.
 * Used by the `studio:start` command's built-in server.
 */
#[Internal]
final readonly class StudioRouter
{
    public function __construct(
        private LandingController $landing,
        private ConsoleOverviewController $consoleOverview,
        private RequestExplorerController $requestExplorer,
        private DatabaseExplorerController $databaseExplorer,
        private LogExplorerController $logExplorer,
        private ExceptionExplorerController $exceptionExplorer,
        private TimelineController $timeline,
        private ApiController $api,
        private BenchmarkController $benchmark,
        private BenchmarkApiController $benchmarkApi,
        private ProductionSafetyMode $safetyMode,
    ) {}

    /**
     * Route a request to the appropriate controller.
     *
     * @throws JsonException
     */
    public function dispatch(Request $request): Response
    {
        $path = '/' . trim($request->path, '/');
        $method = $request->method;

        // POST routes — mutable API actions
        if ($method === Method::POST) {
            return match (true) {
                $path === '/studio/api/benchmark/run' => $this->guardMutableApi($request, fn() => $this->benchmarkApi->run($request)),
                $path === '/studio/api/benchmark/delete' => $this->guardMutableApi($request, fn() => $this->benchmarkApi->deleteRuns($request)),
                $path === '/studio/api/benchmark/clear' => $this->guardMutableApi($request, fn() => $this->benchmarkApi->clearHistory($request)),
                default => new Response(
                    body: 'Method Not Allowed',
                    status: ResponseStatus::MethodNotAllowed,
                    headers: new HeaderBag(['Allow' => 'GET, HEAD']),
                ),
            };
        }

        if ($method !== Method::GET && $method !== Method::HEAD) {
            return new Response(
                body: 'Method Not Allowed',
                status: ResponseStatus::MethodNotAllowed,
                headers: new HeaderBag(['Allow' => 'GET, HEAD']),
            );
        }

        return match (true) {
            $path === '/studio' => $this->landing->handle($request),
            $path === '/studio/console' => $this->consoleOverview->handle($request),
            $path === '/studio/console/requests' => $this->guardDrillDown($request, fn() => $this->requestExplorer->handle($request)),
            $path === '/studio/console/database' => $this->guardDrillDown($request, fn() => $this->databaseExplorer->handle($request)),
            $path === '/studio/console/logs' => $this->guardDrillDown($request, fn() => $this->logExplorer->handle($request)),
            $path === '/studio/console/exceptions' => $this->exceptionExplorer->handle($request),
            $path === '/studio/console/benchmarks' => $this->guardDrillDown($request, fn() => $this->benchmark->handle($request)),
            $this->matchesTimeline($path) => $this->guardDrillDown($request, fn() => $this->timeline->handle($request, $this->extractTimelineId($path))),
            $path === '/studio/api/benchmark/status' => $this->guardApi($request, fn() => $this->benchmarkApi->status($request)),
            $path === '/studio/api/events' => $this->guardApi($request, fn() => $this->api->events($request)),
            $path === '/studio/api/live' => $this->guardSse($request, fn() => $this->api->live($request)),
            default => new Response(body: 'Not Found', status: ResponseStatus::NotFound),
        };
    }

    private function matchesTimeline(string $path): bool
    {
        return (bool) preg_match('#^/studio/console/timeline/[a-f0-9]+$#', $path);
    }

    private function extractTimelineId(string $path): string
    {
        preg_match('#^/studio/console/timeline/([a-f0-9]+)$#', $path, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param callable(): Response $handler
     */
    private function guardDrillDown(Request $_request, callable $handler): Response
    {
        if (!$this->safetyMode->allowDrillDown()) {
            return Response::json(
                ['error' => 'Drill-down views are disabled in production mode'],
                ResponseStatus::Forbidden,
            );
        }

        return $handler();
    }

    /**
     * @param callable(): Response $handler
     */
    private function guardApi(Request $_request, callable $handler): Response
    {
        if (!$this->safetyMode->allowApi()) {
            return Response::json(
                ['error' => 'API access is disabled in production mode'],
                ResponseStatus::Forbidden,
            );
        }

        return $handler();
    }

    /**
     * @param callable(): Response $handler
     */
    private function guardMutableApi(Request $_request, callable $handler): Response
    {
        if (!$this->safetyMode->allowMutableApi()) {
            return Response::json(
                ['error' => 'Mutable API actions are restricted to local development mode'],
                ResponseStatus::Forbidden,
            );
        }

        return $handler();
    }

    /**
     * @param callable(): Response $handler
     */
    private function guardSse(Request $_request, callable $handler): Response
    {
        if (!$this->safetyMode->allowSse()) {
            return Response::json(
                ['error' => 'SSE live stream is disabled in production mode'],
                ResponseStatus::Forbidden,
            );
        }

        return $handler();
    }
}
