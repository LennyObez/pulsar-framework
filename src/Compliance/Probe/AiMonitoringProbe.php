<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether anything actually monitors the models in production.
 *
 * This is the control the audit named: ISO 42001 Clause 9.1 was registered as
 * Implemented on the strength of MonitoringHookInterface, which has ZERO
 * implementations anywhere in this repository — only the interface, the
 * lifecycle manager that references it, and one test double. The probe asks for
 * a resolved hook by name and executes the health checks; on a tree where no
 * hook exists it reports the gap, which is the same fact the catalogue used to
 * report as covered.
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
        return 'Whether a monitoring hook resolved for production models, and whether the health checks pass when executed.';
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
            ObservationId::AiMonitoringHookResolved,
            ObservationId::HealthChecksExecuted,
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
            ObservationId::AiAuditLoggerResolved,
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
            'Register a MonitoringHookInterface implementation; the ai-governance '
                . 'extension ships the interface and no implementation, so nothing monitors '
                . 'a deployed model.',
            'Register health checks covering the model-serving path so monitoring has '
                . 'something to execute.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks rather than leaving Clause 9.1 claimed and '
                . 'unobserved.',
        ];
    }
}
