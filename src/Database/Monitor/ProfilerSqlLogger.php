<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Profiler\RequestProfiler;

/**
 * Forwards executed queries to the request profiler, so its timeline includes
 * database timing, query counts, and row counts.
 */
#[Internal]
final readonly class ProfilerSqlLogger implements SqlLoggerInterface
{
    public function __construct(
        private RequestProfiler $profiler,
    ) {}

    /**
     * @param array<array-key, mixed> $bindings
     */
    #[Override]
    public function log(string $sql, array $bindings, float $durationMs, int $rowCount): void
    {
        $this->profiler->recordQuery($sql, $durationMs, $rowCount);
    }
}
