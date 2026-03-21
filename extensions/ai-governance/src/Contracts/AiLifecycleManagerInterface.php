<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use RuntimeException;

/**
 * Manages AI model lifecycle, deployment gates, and monitoring.
 *
 * ISO 42001:2023 Clause 8.4 requires organizations to manage the lifecycle
 * of AI systems including versioning, validation gates, deployment, monitoring,
 * and rollback capabilities.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiLifecycleManagerInterface
{
    /**
     * Register a deployment gate that models must pass before production deployment.
     */
    public function addDeploymentGate(DeploymentGateInterface $gate): void;

    /**
     * Register a monitoring hook for production models.
     */
    public function addMonitoringHook(MonitoringHookInterface $hook): void;

    /**
     * Evaluate all deployment gates for a model.
     *
     * @return list<array{gate: string, passed: bool, reason: string}>
     */
    #[NoDiscard]
    public function evaluateGates(AiModel $model): array;

    /**
     * Deploy a model to production if all gates pass.
     *
     * @throws RuntimeException If any deployment gate fails
     */
    public function deploy(string $modelId): AiModel;

    /**
     * Run all monitoring hooks for a production model.
     *
     * @param array<string, mixed> $context Additional context for monitoring
     *
     * @return list<MonitoringResult>
     */
    #[NoDiscard]
    public function monitor(string $modelId, array $context = []): array;

    /**
     * Rollback a model from production to its previous status.
     *
     * @throws InvalidArgumentException If the model is not in production
     */
    public function rollback(string $modelId): AiModel;
}
