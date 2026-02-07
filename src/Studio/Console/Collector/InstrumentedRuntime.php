<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector;

use Closure;

use function memory_get_usage;

use Pulsar\Api\Internal;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Runtime\RuntimeInterface;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\RuntimeLeakWarningPayload;
use Pulsar\Studio\Console\Event\Payload\RuntimeRequestCompletePayload;
use Pulsar\Studio\Console\Event\Payload\RuntimeSchedulerMetricPayload;
use Pulsar\Studio\Console\Event\Payload\RuntimeWorkerRecyclePayload;
use Pulsar\Studio\Console\Event\Payload\RuntimeWorkerStartPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;
use Throwable;

/**
 * Decorator that instruments the persistent runtime for Studio.
 *
 * Emits events and records metrics via MetricRegistry. Follows the
 * same decorator + emit pattern as InstrumentedScheduler.
 */
#[Internal]
final class InstrumentedRuntime implements CollectorInterface
{
    public bool $enabled = true;

    private int $requestCount = 0;
    private int $totalSpawned = 0;
    private int $totalCompleted = 0;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly RuntimeInterface $inner,
        private readonly FiberScopedContextProvider $contextProvider,
        private readonly MetricRegistry $metricRegistry,
        private readonly Closure $emit,
    ) {
        $this->registerMetrics();
    }

    /**
     * Emit a worker start event.
     */
    public function emitWorkerStart(
        string $host,
        int $port,
        int $fiberConcurrency,
        int $maxRequests,
        int $memoryThresholdMb,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $event = new RuntimeWorkerStartPayload(
            host: $host,
            port: $port,
            fiberConcurrency: $fiberConcurrency,
            maxRequests: $maxRequests,
            memoryThresholdMb: $memoryThresholdMb,
            startedAt: microtime(true),
        );

        $this->safeEmit($event);
    }

    /**
     * Record a completed request.
     */
    public function recordRequest(
        Request $request,
        Response $response,
        float $durationMs,
        int $memoryDeltaBytes,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->requestCount++;
        $this->totalCompleted++;

        $this->metricRegistry->counter('runtime_requests_total', 'Total runtime requests')->increment();
        $this->metricRegistry->histogram(
            'runtime_request_duration_ms',
            'Request duration in milliseconds',
        )->observe($durationMs);
        $this->metricRegistry->gauge(
            'runtime_memory_bytes',
            'Current runtime memory usage',
        )->set((float) memory_get_usage(true));

        // Track slow requests (>1000ms)
        if ($durationMs > 1000.0) {
            $this->metricRegistry->counter(
                'runtime_slow_requests_total',
                'Total slow requests (>1s)',
            )->increment();
        }

        $event = new RuntimeRequestCompletePayload(
            method: $request->method->value,
            path: $request->path,
            statusCode: $response->status->value,
            durationMs: $durationMs,
            memoryDeltaBytes: $memoryDeltaBytes,
        );

        $this->safeEmit($event);
    }

    /**
     * Emit a worker recycle event.
     */
    public function emitWorkerRecycle(
        string $reason,
        int $requestCount,
        int $memoryUsageMb,
        int $uptimeSeconds,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->metricRegistry->counter(
            'runtime_worker_restarts_total',
            'Total worker restarts',
        )->increment();

        $event = new RuntimeWorkerRecyclePayload(
            reason: $reason,
            requestCount: $requestCount,
            memoryUsageMb: $memoryUsageMb,
            uptimeSeconds: $uptimeSeconds,
        );

        $this->safeEmit($event);
    }

    /**
     * Emit a leak warning event.
     *
     * @param list<string> $warnings
     */
    public function emitLeakWarning(
        array $warnings,
        int $memoryDeltaBytes,
        int $requestNumber,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $event = new RuntimeLeakWarningPayload(
            warnings: $warnings,
            memoryDeltaBytes: $memoryDeltaBytes,
            requestNumber: $requestNumber,
        );

        $this->safeEmit($event);
    }

    /**
     * Emit a Fiber scheduler metric event.
     */
    public function emitSchedulerMetric(
        int $activeFibers,
        float $uptimeSeconds,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->metricRegistry->gauge(
            'runtime_active_fibers',
            'Active Fiber count',
        )->set((float) $activeFibers);

        $event = new RuntimeSchedulerMetricPayload(
            activeFibers: $activeFibers,
            totalSpawned: $this->totalSpawned,
            totalCompleted: $this->totalCompleted,
            uptimeSeconds: $uptimeSeconds,
        );

        $this->safeEmit($event);
    }

    /**
     * Track a new Fiber spawn.
     */
    public function trackFiberSpawn(): void
    {
        $this->totalSpawned++;
    }

    /**
     * Get the inner runtime.
     */
    public function inner(): RuntimeInterface
    {
        return $this->inner;
    }

    private function registerMetrics(): void
    {
        $this->metricRegistry->counter('runtime_requests_total', 'Total runtime requests');
        $this->metricRegistry->histogram('runtime_request_duration_ms', 'Request duration in milliseconds');
        $this->metricRegistry->gauge('runtime_memory_bytes', 'Current runtime memory usage');
        $this->metricRegistry->counter('runtime_worker_restarts_total', 'Total worker restarts');
        $this->metricRegistry->gauge('runtime_active_fibers', 'Active Fiber count');
        $this->metricRegistry->counter('runtime_slow_requests_total', 'Total slow requests (>1s)');
    }

    private function safeEmit(ConsoleEvent $event): void
    {
        try {
            ($this->emit)($event, $this->contextProvider->current());
        } catch (Throwable) {
        }
    }
}
