<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Pulsar\Security\Compliance\ComplianceEvent;

/**
 * No-op compliance event dispatcher for Tier A benchmarks.
 *
 * Accepts events silently without persisting or forwarding.
 */
final class NullComplianceDispatcher
{
    private int $dispatchCount = 0;

    public function dispatch(ComplianceEvent $event): void
    {
        $this->dispatchCount++;
    }

    public function dispatchCount(): int
    {
        return $this->dispatchCount;
    }

    public function reset(): void
    {
        $this->dispatchCount = 0;
    }
}
