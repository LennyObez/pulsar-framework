<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the deployment can actually delete personal data when asked.
 *
 * A purge orchestrator that resolved is evidence; a documented retention
 * schedule is not, because a schedule is a statement of intent and erasure is
 * an act.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class DataErasureProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.data_erasure';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Which data-purge implementation resolved, and whether a retention schedule drives it.';
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
            ObservationId::ErasureSubsystemResolved,
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
            ObservationId::RetentionBounded,
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
            'Bind at least one DataPurgeInterface implementation covering the stores '
                . 'that hold personal data.',
        ];
    }
}
