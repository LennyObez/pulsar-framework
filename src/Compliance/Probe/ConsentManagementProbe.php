<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether consent recorded today can still be demonstrated tomorrow.
 *
 * InMemoryConsentManager is refused deliberately. The obligation is not to
 * collect consent but to be able to DEMONSTRATE it afterwards, and a record
 * that vanishes with the process demonstrates nothing.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ConsentManagementProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.consent_management';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which consent manager resolved, and whether its records survive a restart.';
    }

    #[Override]
    #[NoDiscard]
    protected function scope(): ObservationId
    {
        return ObservationId::ScopeProcessesPersonalData;
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::ConsentSubsystemResolved,
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
            ObservationId::RetentionScheduleResolved,
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
            'Bind ConsentManagerInterface to a manager that persists; '
                . 'InMemoryConsentManager loses every record on restart, so consent cannot '
                . 'be demonstrated afterwards.',
        ];
    }
}
