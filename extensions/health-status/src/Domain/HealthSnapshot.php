<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Resilience\HealthCheck\HealthStatus;

/**
 * Point-in-time snapshot of all health check results.
 *
 * Each snapshot captures the overall system status, individual check
 * results with latency, total duration, and the exact capture time.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthSnapshot
{
    /**
     * @param string $id Unique snapshot identifier (UUID)
     * @param HealthStatus $overallStatus Worst status across all checks
     * @param list<array{name: string, status: string, message: string, latency_ms: float}> $results Individual check results
     * @param float $totalDurationMs Total time to run all checks
     * @param DateTimeImmutable $capturedAt When the snapshot was taken
     */
    public function __construct(
        public string $id,
        public HealthStatus $overallStatus,
        public array $results,
        public float $totalDurationMs,
        public DateTimeImmutable $capturedAt,
    ) {}

    /**
     * @return array{id: string, overall_status: string, results: list<array{name: string, status: string, message: string, latency_ms: float}>, total_duration_ms: float, captured_at: string}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'overall_status' => $this->overallStatus->value,
            'results' => $this->results,
            'total_duration_ms' => $this->totalDurationMs,
            'captured_at' => $this->capturedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array{
     *     id: string,
     *     overall_status: string,
     *     results: list<array{name: string, status: string, message: string, latency_ms: float}>,
     *     total_duration_ms: float|int|string,
     *     captured_at: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            overallStatus: HealthStatus::from($data['overall_status']),
            results: $data['results'],
            totalDurationMs: (float) $data['total_duration_ms'],
            capturedAt: new DateTimeImmutable($data['captured_at']),
        );
    }
}
