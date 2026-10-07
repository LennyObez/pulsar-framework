<?php

declare(strict_types=1);

namespace Pulsar\Http\Controller;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
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
 *
 * The status code is the load balancer's whole contract and is identical for
 * every caller. The check DETAIL is not: `%.0f MB free on /srv/app/var`,
 * `Database check failed: SQLSTATE[08006] could not connect to host db-01`,
 * `Cache responded in 812.4ms` — absolute paths, infrastructure hostnames,
 * driver errors and timing side channels, previously returned in full to any
 * anonymous request that could reach the probe. A health endpoint has to be
 * open to be useful, which makes it the wrong place to publish the shape of the
 * estate behind it.
 *
 * So detail is authorized separately, by the same operator credential that
 * opens the diagnostics dashboard and the metrics exporter. Callers without it
 * still get the overall status, the name of every check, and each check's
 * status — enough to tell an orchestrator what to do and enough for an operator
 * to see which check is red — but no free-text message and no latency.
 *
 * Without a `$detailAuthorizer` the controller answers every caller in the
 * reduced form: an absent authorizer means nothing is in a position to say a
 * request is privileged, and that is a refusal rather than a waiver.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthController
{
    /**
     * @param (Closure(ServerRequestInterface): bool)|null $detailAuthorizer Decides whether a
     *        request may see check messages and latencies; nothing may when null
     */
    public function __construct(
        private HealthCheckRunnerInterface $runner,
        private ?Closure $detailAuthorizer = null,
    ) {}

    public function __invoke(?ServerRequestInterface $request = null): Response
    {
        $report = $this->runner->runAll();

        $detailed = $request !== null
            && $this->detailAuthorizer !== null
            && ($this->detailAuthorizer)($request);

        $checks = [];

        foreach ($report->results as $result) {
            $checks[$result->name] = $detailed
                ? [
                    'status' => $result->status->value,
                    'message' => $result->message,
                    'latency_ms' => round($result->responseTimeMs, 2),
                ]
                : ['status' => $result->status->value];
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
