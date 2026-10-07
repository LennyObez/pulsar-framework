<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether a data subject asking what is held about them would reach anything.
 *
 * The framework ships the DSAR request handler, the packager and the deadline
 * tracker, and no store to put a request in: nothing in the tree implements
 * DsarStoreInterface. Until a deployment binds one, an access request has nowhere
 * to land, and the control reports that rather than the presence of the classes
 * around the hole.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SubjectRightsProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.subject_rights';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether a data-subject request store resolved, so an access request can be recorded and answered.';
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
            ObservationId::SubjectRequestHandlerResolved,
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
            ObservationId::ErasureSubsystemResolved,
            ObservationId::ConsentSubsystemResolved,
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
            'Bind a DsarStoreInterface implementation; the framework ships the request '
                . 'handler, the packager and the deadline tracker but no store, so a subject '
                . 'access request currently has nowhere to be recorded.',
        ];
    }
}
