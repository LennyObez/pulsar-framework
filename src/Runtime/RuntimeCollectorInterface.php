<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Contract for runtime instrumentation collectors.
 *
 * Allows the persistent runtime to emit lifecycle events and record
 * request metrics without depending on a concrete collector implementation.
 * Extensions (e.g., Studio) provide the concrete implementation.
 */
#[Api(since: '1.0.0')]
interface RuntimeCollectorInterface
{
    public function emitWorkerStart(
        string $host,
        int $port,
        int $fiberConcurrency,
        int $maxRequests,
        int $memoryThresholdMb,
    ): void;

    public function trackFiberSpawn(): void;

    public function recordRequest(
        Request $request,
        Response $response,
        float $durationMs,
        int $memoryDeltaBytes,
    ): void;

    public function emitWorkerRecycle(
        string $reason,
        int $requestCount,
        int $memoryUsageMb,
        int $uptimeSeconds,
    ): void;
}
