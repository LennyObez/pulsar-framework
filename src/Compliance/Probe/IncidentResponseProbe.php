<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether a security incident recorded today would still exist tomorrow.
 *
 * InMemoryIncidentReporter is refused deliberately: an incident register that
 * empties on restart cannot evidence a notification deadline, which is the only
 * thing every incident-response requirement actually asks for.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class IncidentResponseProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.incident_response';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which incident reporter resolved, and whether it survives a restart.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::IncidentReporterResolved,
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
            ObservationId::BreachNotificationDeadlineSet,
            ObservationId::AuditChainVerified,
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
            'Bind IncidentReporterInterface to FileIncidentReporter or another '
                . 'reporter that persists; InMemoryIncidentReporter loses the register on '
                . 'restart.',
            'Enable a framework whose profile sets a breach-notification deadline, so '
                . 'the recorded incident has a clock attached.',
        ];
    }
}
