<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;

/**
 * Hook for monitoring AI models in production.
 *
 * ISO 42001:2023 Clause 9.1 requires continuous monitoring and measurement
 * of AI system performance. Integrators register monitoring hooks to track
 * drift, performance degradation, and bias emergence.
 */
#[Api(since: '1.0.0')]
interface MonitoringHookInterface
{
    /**
     * Get the unique name of this monitoring hook.
     */
    public function name(): string;

    /**
     * Execute the monitoring check for a model.
     *
     * Implementations should collect metrics, detect anomalies, or trigger
     * alerts as appropriate for their monitoring concern.
     *
     * @param array<string, mixed> $context Additional context (e.g., recent invocation data)
     */
    public function check(AiModel $model, array $context = []): MonitoringResult;
}
