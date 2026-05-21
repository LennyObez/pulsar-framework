<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Worker;

use Pulsar\Api\Internal;
use Pulsar\Runtime\RuntimeType;

/**
 * Immutable snapshot of worker state for diagnostics and health checks.
 */
#[Internal]
final readonly class WorkerInfo
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public int $pid,
        public int $startedAt,
        public int $requestCount,
        public int $memoryUsageMb,
        public WorkerState $state,
        public RuntimeType $runtimeType,
    ) {}
}
