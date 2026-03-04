<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Http\Message\Response;

/**
 * Handles GET /_pulsar/status: renders the HTML status dashboard.
 *
 * Runs all registered health checks, retrieves recent history and
 * active incidents, then renders the dashboard Pulse template.
 */
#[Internal]
final readonly class StatusDashboardController
{
    use RendersStatusView;

    public function __construct(
        private HealthHistoryStoreInterface $store,
        private HealthCheckRunnerInterface $runner,
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        $current = $this->runner->run();
        $this->store->storeSnapshot($current);

        $history = $this->store->recentSnapshots(24);
        $incidents = $this->store->activeIncidents();

        $html = $this->renderView('System Status', 'dashboard', [
            'current' => $current,
            'history' => $history,
            'incidents' => $incidents,
        ]);

        return Response::html($html);
    }
}
