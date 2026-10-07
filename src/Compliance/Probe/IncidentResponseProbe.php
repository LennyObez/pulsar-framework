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
 *
 * WHY THIS PROBE DOES NOT TAKE {@see ObservationId::IncidentRecordedAndRetained},
 * though {@see BreachNotificationProbe} beside it now does, and the fact would
 * pass the estate join for every control here. It is a per-control reading rather
 * than an oversight, and it is deliberately narrow.
 *
 * That fact establishes that a record can be written, dated and produced again.
 * For a NOTIFICATION control — GDPR Art 33, NIS2 Art 23 — that is the whole of
 * what the framework delivers: the obligation is to notify within a deadline, and
 * the deliverable half is a dated record somebody can still find. The controls
 * cited HERE are about an incident-management PROCESS: DORA Articles 17-23 ask an
 * entity to "detect, manage and notify", NIS2 Art 21(2)(b) asks for incident
 * handling, SOC 2 CC7.3 and CC7.4 for evaluation and response, MDR for vigilance
 * reporting. Detection and management are not observed here at all, and a register
 * that works evidences one third of what each of those asks.
 *
 * Whether that third is enough for any of them is a reading of seven standards,
 * one at a time, and it belongs to whoever does that reading rather than to this
 * change. The controls stay Unsatisfied until then, which is the honest state:
 * their gap is real and this probe names it.
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
