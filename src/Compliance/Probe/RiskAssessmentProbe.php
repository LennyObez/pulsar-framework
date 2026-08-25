<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the deployment continuously checks its own security posture rather
 * than asserting it.
 *
 * The inert-feature detector is the substantive half: a risk assessment that
 * cannot see a security control gone inert is not assessing the risk that
 * matters most.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RiskAssessmentProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.risk_assessment';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a compliance profile is resolved and no security feature is silently inert.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::ComplianceProfileResolved,
            ObservationId::SecurityFeaturesIntact,
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
            ObservationId::FipsValidatedCryptography,
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
            'Enable the frameworks the deployment must satisfy so a profile exists to '
                . 'assess against.',
            'Bind the optional services the wiring contracts name, so no security '
                . 'feature is inert.',
        ];
    }
}
