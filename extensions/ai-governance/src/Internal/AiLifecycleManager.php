<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use InvalidArgumentException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use RuntimeException;

use function sprintf;

/**
 * Manages AI model lifecycle with deployment gates and monitoring hooks.
 */
#[Internal(reason: 'Internal implementation; use AiLifecycleManagerInterface for public access')]
final class AiLifecycleManager implements AiLifecycleManagerInterface
{
    /** @var list<DeploymentGateInterface> */
    private array $gates = [];

    /** @var list<MonitoringHookInterface> */
    private array $hooks = [];

    public function __construct(
        private readonly AiModelRegistryInterface $registry,
        private readonly AiAuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function addDeploymentGate(DeploymentGateInterface $gate): void
    {
        $this->gates[] = $gate;
    }

    #[Override]
    public function addMonitoringHook(MonitoringHookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    #[Override]
    public function evaluateGates(AiModel $model): array
    {
        $results = [];

        foreach ($this->gates as $gate) {
            $passed = $gate->evaluate($model);
            $results[] = [
                'gate' => $gate->name(),
                'passed' => $passed,
                'reason' => $passed ? '' : $gate->failureReason(),
            ];
        }

        return $results;
    }

    #[Override]
    public function deploy(string $modelId): AiModel
    {
        $model = $this->registry->get($modelId);

        if ($model === null) {
            throw new InvalidArgumentException(sprintf('AI model "%s" not found in registry', $modelId));
        }

        $gateResults = $this->evaluateGates($model);
        $failures = [];

        foreach ($gateResults as $result) {
            if (! $result['passed']) {
                $failures[] = sprintf('%s: %s', $result['gate'], $result['reason']);

                $this->auditLogger->logAiEvent(
                    event: AiAuditEvent::DeploymentGateFailed,
                    modelId: $modelId,
                    action: 'ai.deployment_gate_failed',
                    resource: $result['gate'],
                    metadata: ['reason' => $result['reason']],
                );
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Deployment of model "%s" blocked by gate failures: %s',
                $modelId,
                implode('; ', $failures),
            ));
        }

        $deployed = $this->registry->transitionStatus($modelId, AiModelStatus::Production);

        $this->auditLogger->logAiEvent(
            event: AiAuditEvent::ModelDeployed,
            modelId: $modelId,
            action: 'ai.model_deployed',
            resource: $modelId,
        );

        return $deployed;
    }

    #[Override]
    public function monitor(string $modelId, array $context = []): array
    {
        $model = $this->registry->get($modelId);

        if ($model === null) {
            throw new InvalidArgumentException(sprintf('AI model "%s" not found in registry', $modelId));
        }

        $results = [];

        foreach ($this->hooks as $hook) {
            $results[] = $hook->check($model, $context);
        }

        return $results;
    }

    #[Override]
    public function rollback(string $modelId): AiModel
    {
        $model = $this->registry->get($modelId);

        if ($model === null) {
            throw new InvalidArgumentException(sprintf('AI model "%s" not found in registry', $modelId));
        }

        if ($model->status !== AiModelStatus::Production) {
            throw new InvalidArgumentException(sprintf(
                'Cannot rollback model "%s": current status is "%s", expected "production"',
                $modelId,
                $model->status->value,
            ));
        }

        $rolledBack = $this->registry->transitionStatus($modelId, AiModelStatus::Staging);

        $this->auditLogger->logAiEvent(
            event: AiAuditEvent::ModelRetired,
            modelId: $modelId,
            action: 'ai.model_rolled_back',
            resource: $modelId,
        );

        return $rolledBack;
    }
}
