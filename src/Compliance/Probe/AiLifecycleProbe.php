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
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks.',
        ];
    }
}
