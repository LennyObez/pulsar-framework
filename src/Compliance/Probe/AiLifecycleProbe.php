<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether models are moved between lifecycle stages by something that can also
 * refuse the move.
 *
 * The deployment gate is required alongside the lifecycle manager: a lifecycle
 * with no gate is a state machine, not a control.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiLifecycleProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_lifecycle';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI governance extension is active, a lifecycle manager resolved and a deployment gate can refuse a promotion.';
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
            ObservationId::AiLifecycleManagerResolved,
            ObservationId::AiDeploymentGateResolved,
            // Added in rc.12. A life cycle is a sequence, and a manager whose
            // registry forgets which stage a model reached cannot govern one: the
            // gates would re-run against an inventory that starts empty at every
            // restart, and a model recorded as retired would come back unknown
            // rather than retired.
            ObservationId::AiGovernanceRecordsDurable,
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
            ObservationId::AiModelRegistryResolved,
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
            'Install and enable the pulsar/ai-governance extension.',
            'Bind at least one DeploymentGateInterface implementation; a lifecycle '
                . 'with no gate cannot refuse an unsafe deployment.',
            'Point the ai_governance store keys at a durable implementation. The extension ships '
                . 'one for each store — "database" is the default in config/ai-governance.php since '
                . 'rc.12 — and `pulsar migrate` creates the tables. An in-memory store loses the '
                . 'record at the next restart, which is a gap this check reports rather than '
                . 'assumes.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks.',
        ];
    }
}
