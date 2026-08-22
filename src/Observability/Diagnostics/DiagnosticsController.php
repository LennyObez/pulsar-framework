<?php

declare(strict_types=1);

namespace Pulsar\Observability\Diagnostics;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;

/**
 * Renders the `/_pulsar/diagnostics` page behind a Bearer-token guard.
 *
 * A class-based handler (registered as `[DiagnosticsController::class, 'show']`)
 * rather than a closure, so the route compiles into the strict route cache.
 * Off by default: without a configured `PULSAR_DIAGNOSTICS_TOKEN` the guard
 * refuses every request (constant-time comparison).
 */
#[Internal]
final readonly class DiagnosticsController
{
    public function __construct(
        private DiagnosticsAuthGuard $guard,
        private MetricRegistry $registry,
        private ?InMemorySpanCollector $spanCollector = null,
        private ?ErrorAggregator $errorAggregator = null,
    ) {}

    public function show(ServerRequestInterface $request): Response
    {
        if (!$this->guard->isAuthorized($request)) {
            return Response::text(
                'Diagnostics endpoint requires Bearer token from PULSAR_DIAGNOSTICS_TOKEN.',
                ResponseStatus::Unauthorized->value,
            )->withHeader('WWW-Authenticate', 'Bearer realm="pulsar-diagnostics"');
        }

        $renderer = new DiagnosticsRenderer($this->registry, $this->spanCollector, $this->errorAggregator);

        return Response::html($renderer->render());
    }
}
