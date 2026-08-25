<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether a breach could be recorded, dated and notified within the deadline the
 * profile imposes.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BreachNotificationProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.breach_notification';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which incident reporter resolved, and what notification deadline the profile imposes.';
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
            ObservationId::AuditSinkResolved,
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
            'Bind a persisting IncidentReporterInterface; a register that empties on '
                . 'restart cannot evidence a notification deadline.',
        ];
    }
}
