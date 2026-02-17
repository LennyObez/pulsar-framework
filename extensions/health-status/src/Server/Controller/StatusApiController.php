<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Http\Message\Response;

use function array_map;
use function count;
use function is_numeric;
use function max;
use function min;

/**
 * JSON API endpoints for the health-status extension.
 *
 * Provides machine-readable health data with appropriate cache headers.
 */
#[Internal]
final readonly class StatusApiController
{
    public function __construct(
        private HealthHistoryStoreInterface $store,
        private HealthCheckRunnerInterface $runner,
    ) {}

    /**
     * GET /_pulsar/status/api/current: current health as JSON.
     */
    public function current(ServerRequestInterface $request): Response
    {
        $snapshot = $this->runner->run();
        $this->store->storeSnapshot($snapshot);

        $response = Response::json($snapshot->toArray());

        return $response->withHeader('Cache-Control', 'public, max-age=10');
    }

    /**
     * GET /_pulsar/status/api/history: recent snapshots as JSON.
     */
    public function history(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $limit = 50;

        if (isset($params['limit']) && is_numeric($params['limit'])) {
            $limit = (int) max(1, min(200, (int) $params['limit']));
        }

        $snapshots = $this->store->recentSnapshots($limit);

        return Response::json([
            'snapshots' => array_map(
                static fn(HealthSnapshot $s): array => $s->toArray(),
                $snapshots,
            ),
            'count' => count($snapshots),
        ]);
    }

    /**
     * GET /_pulsar/status/api/incidents: active + recent incidents as JSON.
     */
    public function incidents(ServerRequestInterface $request): Response
    {
        $active = $this->store->activeIncidents();
        $recent = $this->store->recentIncidents(20);

        return Response::json([
            'active' => array_map(
                static fn(Incident $i): array => $i->toArray(),
                $active,
            ),
            'recent' => array_map(
                static fn(Incident $i): array => $i->toArray(),
                $recent,
            ),
            'active_count' => count($active),
        ]);
    }
}
