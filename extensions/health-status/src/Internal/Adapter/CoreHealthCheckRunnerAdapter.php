<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Internal\Adapter;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface as CoreHealthCheckRunnerInterface;

use function bin2hex;
use function random_bytes;

/**
 * Adapts the core HealthCheckRunner into the extension's HealthCheckRunnerInterface.
 *
 * Calls the core runner's runAll() method and converts the HealthReport
 * into a HealthSnapshot suitable for history storage and dashboard display.
 */
#[Internal(reason: 'Adapter wiring; not part of public API')]
final readonly class CoreHealthCheckRunnerAdapter implements HealthCheckRunnerInterface
{
    public function __construct(
        private CoreHealthCheckRunnerInterface $coreRunner,
    ) {}

    #[Override]
    public function run(): HealthSnapshot
    {
        $startTime = hrtime(true);
        $report = $this->coreRunner->runAll();
        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $results = [];

        foreach ($report->results as $result) {
            $results[] = [
                'name' => $result->name,
                'status' => $result->status->value,
                'message' => $result->message,
                'latency_ms' => $result->responseTimeMs,
            ];
        }

        return new HealthSnapshot(
            id: bin2hex(random_bytes(16)),
            overallStatus: $report->overallStatus,
            results: $results,
            totalDurationMs: $durationMs,
            capturedAt: new DateTimeImmutable(),
        );
    }
}
