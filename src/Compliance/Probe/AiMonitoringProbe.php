<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether anything actually monitors the models in production, and whether the
 * result is kept.
 *
 * WHAT THIS CONTROL USED TO REST ON. ISO 42001 Clause 9.1 was registered as
 * Implemented on the strength of `MonitoringHookInterface`, which had ZERO
 * implementations anywhere in this repository — only the interface, the lifecycle
 * manager that references it, and one test double. The probe was then rewritten to
 * ask for a RESOLVED hook, which was honest and inert: no deployment could bind
 * one, so the clause was stuck Unsatisfied for a reason that was a gap in the
 * framework rather than a property of any deployment.
 *
 * WHAT IT RESTS ON NOW. {@see ObservationId::AiMonitoringExercised}, produced by
 * running the deployment's registered hooks against a registered model and
 * requiring the results to have been RETAINED where a store instance that did not
 * write them can read them. Both halves are Clause 9.1: the clause asks what is
 * monitored and by which method, and it closes by requiring documented information
 * to be retained as evidence of the results. A hook that runs and returns into the
 * void satisfies the first half and leaves an auditor with what a deployment that
 * never monitored would show them.
 *
 * `ai_monitoring_hook_resolved` is now SUPPORTING rather than required, and the
 * demotion is the point of the rewrite: it is a resolution, so it can block a
 * Satisfied verdict and can never produce one, and it was carrying a clause about
 * behaviour. It stays in the report because which hook answered is worth printing
 * beside the fact that a hook ran.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiMonitoringProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_monitoring';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the registered monitoring hooks ran against a model in the inventory and '
            . 'their results were retained as documented information.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::AiGovernanceExtensionActive,
            ObservationId::AiMonitoringExercised,
        ];
    }

    /**
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::AiMonitoringHookResolved,
            ObservationId::AiAuditLoggerResolved,
            // Kept as corroboration and deliberately no longer required. Health
            // checks cover the serving path's liveness, which is a different
            // estate from an AI system's behaviour, and requiring them meant a
            // deployment with no health check registered failed the AI monitoring
            // clause for a reason that has nothing to do with AI.
            ObservationId::HealthChecksExecuted,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Register at least one MonitoringHookInterface implementation through '
                . 'AiLifecycleManagerInterface::addMonitoringHook(). The extension registers '
                . 'GovernanceConformityHook by default, which re-reads on a deployed model the '
                . 'obligations it was admitted under; it is a floor, not a monitoring plan, and a '
                . 'deployment subject to EU AI Act Article 72(3) owes hooks for model performance '
                . 'and drift alongside it.',
            'Point ai_governance.monitoring_record_store at a durable implementation — '
                . '"database" is the default in config/ai-governance.php — and run '
                . '`pulsar migrate`. ISO 42001 Clause 9.1 requires documented information to be '
                . 'retained as evidence of the monitoring results, and a result that is returned '
                . 'and dropped evidences nothing.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks rather than leaving Clause 9.1 claimed and '
                . 'unobserved.',
        ];
    }
}
