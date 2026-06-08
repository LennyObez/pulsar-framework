<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Contracts\IncidentDetectorInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function array_map;
use function bin2hex;
use function random_bytes;

/**
 * Captures a health check snapshot every minute and runs incident detection.
 *
 * Executes all registered health checks via the runner, stores the results
 * as a snapshot, then delegates to the incident detector to create or
 * resolve incidents based on consecutive failure patterns.
 */
final readonly class HealthCheckSnapshotJob implements JobInterface
{
    public function __construct(
        private HealthCheckRunnerInterface $runner,
        private HealthHistoryStoreInterface $store,
        private IncidentDetectorInterface $detector,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'health-status:snapshot';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::everyMinute();
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        try {
            $report = $this->runner->runAll();

            $snapshot = new HealthSnapshot(
                id: bin2hex(random_bytes(16)),
                overallStatus: $report->overallStatus,
                results: array_map(
                    static fn($r): array => [
                        'name' => $r->name,
                        'status' => $r->status->value,
                        'message' => $r->message,
                        'latency_ms' => $r->responseTimeMs,
                    ],
                    $report->results,
                ),
                totalDurationMs: $this->calculateTotalDuration($report->results),
                capturedAt: new DateTimeImmutable(),
            );

            $this->store->storeSnapshot($snapshot);

            $incidents = $this->detector->detect($snapshot, $this->store);

            foreach ($incidents as $incident) {
                if ($incident->status === IncidentStatus::Resolved) {
                    $this->store->updateIncident($incident);
                } else {
                    $this->store->storeIncident($incident);
                }
            }

            return JobResult::success($this->getName(), $context->startedAt, "Snapshot stored, {$report->overallStatus->value}");
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $context->startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Captures health check results and detects incidents';
    }

    /**
     * @param list<\Pulsar\Resilience\HealthCheck\HealthCheckResult> $results
     */
    private function calculateTotalDuration(array $results): float
    {
        $total = 0.0;

        foreach ($results as $result) {
            $total += $result->responseTimeMs;
        }

        return $total;
    }
}
