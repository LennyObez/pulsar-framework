<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringRecordStoreInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function sprintf;

/**
 * Manages AI model lifecycle with deployment gates and monitoring hooks.
 *
 * The hook collection is held by a {@see MonitoringHookRegistryInterface} rather
 * than by this class, because the high-risk deployment gate has to see it too:
 * EU AI Act Article 72 makes post-market monitoring a precondition of
 * deployment, not something that starts existing afterwards.
 *
 * RETENTION LIVES HERE, not in the hooks. ISO 42001 Clause 9.1 ends by requiring
 * documented information to be retained as evidence of the monitoring results,
 * and until {@see MonitoringRecordStoreInterface} existed {@see monitor()} ran
 * every hook and handed the results to its caller, who was free to drop them —
 * which left a deployment that monitored continuously with nothing to show an
 * auditor. This class is where retention belongs because it is the one component
 * that sees every hook's result, including the hooks an integrator registers:
 * putting the write inside a hook would retain only the hooks that remembered to
 * do it, and would make writing a hook a storage problem.
 */
#[Internal(reason: 'Internal implementation; use AiLifecycleManagerInterface for public access')]
final class AiLifecycleManager implements AiLifecycleManagerInterface
{
    /** @var list<DeploymentGateInterface> */
    private array $gates = [];

    /**
     * @param ClockInterface|null $clock Supplies the instant each retained record
     *        carries. Injected so a test can state what "when" was, and defaulted
     *        rather than required because a lifecycle manager built without one
     *        must still stamp a real time rather than none
     */
    public function __construct(
        private readonly AiModelRegistryInterface $registry,
        private readonly AiAuditLoggerInterface $auditLogger,
        private readonly MonitoringHookRegistryInterface $monitoringHooks,
        private readonly MonitoringRecordStoreInterface $monitoringRecords,
        private readonly ?ClockInterface $clock = null,
    ) {}

    #[Override]
    public function addDeploymentGate(DeploymentGateInterface $gate): void
    {
        $this->gates[] = $gate;
    }

    #[Override]
    public function addMonitoringHook(MonitoringHookInterface $hook): void
    {
        $this->monitoringHooks->add($hook);
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
            throw AiGovernanceException::modelNotFound($modelId);
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
            throw AiGovernanceException::deploymentBlocked($modelId, $failures);
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
            throw AiGovernanceException::modelNotFound($modelId);
        }

        $results = [];
        $observedAt = $this->clock?->now() ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->monitoringHooks->all() as $hook) {
            $result = $hook->check($model, $context);

            // Retained before it is returned, and retained whether or not the
            // check passed. A store that kept only the failures would be a
            // register of incidents; Clause 9.1 asks for evidence of the results,
            // and "the check ran and found nothing wrong" is the result an
            // assessor most often needs to see a run of.
            $this->monitoringRecords->record(MonitoringRecord::of(
                $model->id,
                $result,
                DateTimeImmutable::createFromInterface($observedAt),
            ));

            $results[] = $result;
        }

        return $results;
    }

    #[Override]
    public function rollback(string $modelId): AiModel
    {
        $model = $this->registry->get($modelId);

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        if ($model->status !== AiModelStatus::Production) {
            throw AiGovernanceException::rollbackFromNonProduction(
                $modelId,
                $model->status->value,
            );
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
