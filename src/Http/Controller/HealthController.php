<?php

declare(strict_types=1);

namespace Pulsar\Http\Controller;

use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthStatus;

use function round;

/**
 * HTTP endpoint that exposes system health status as JSON.
 *
 * Returns 200 when all checks pass, 503 when any check is unhealthy
 * or degraded. Intended for load balancers and orchestrators.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthController
{
    public function __construct(
        private HealthCheckRunnerInterface $runner,
    ) {}

    public function __invoke(): Response
    {
        $report = $this->runner->runAll();

        $checks = [];

        foreach ($report->results as $result) {
            $checks[$result->name] = [
                'status' => $result->status->value,
                'message' => $result->message,
                'latency_ms' => round($result->responseTimeMs, 2),
            ];
        }

        $body = [
            'status' => $report->overallStatus->value,
            'checks' => $checks,
            'timestamp' => $report->generatedAt->format('c'),
        ];

        $statusCode = $report->overallStatus === HealthStatus::Healthy ? 200 : 503;

        return Response::json($body, $statusCode);
    }
}
