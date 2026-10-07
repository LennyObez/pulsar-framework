<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ControlProbeInterface;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\RequiredFact;

/**
 * Whether a breach recorded now can be produced again, with the clock the
 * notification deadline runs from.
 *
 * WHAT THIS PROBE USED TO ASK. It required
 * {@see ObservationId::IncidentReporterResolved} alone — which class answered
 * `IncidentReporterInterface` — so GDPR Art 33 and NIS2 Art 23 failed on every
 * default installation as "claimed and not observed". They were right to: a
 * register that WOULD survive a restart, with nothing ever written to it, has
 * never dated an incident and has never given one back. ADR-0046 left that
 * failure standing rather than configuring it away.
 *
 * SO THE DECIDING FACT IS {@see ObservationId::IncidentRecordedAndRetained}: a
 * synthetic incident recorded through the live register, dated, and retrieved by
 * id with its severity, title, metadata and timestamp unchanged. Article 33 gives
 * 72 hours and Article 23 gives 24, and a deadline is measured from a timestamp
 * on a record somebody can still find. See
 * {@see \Pulsar\Compliance\Evidence\IncidentRegisterObserver} for what it writes,
 * and for why — uniquely in the evidence set — it cannot take that write back.
 *
 * {@see ObservationId::IncidentReporterResolved} STAYS, and stays ESSENTIAL. The
 * measurement runs in one process, and `InMemoryIncidentReporter` passes every
 * one of its subjects before losing the register at the end of the request. That
 * is not a weaker register; it is one that cannot evidence a deadline it does not
 * outlive, which is the only thing an incident-reporting obligation actually asks
 * for. Durability is a question about which class answered, and no in-process
 * measurement can ask it.
 *
 * Declared as its own class rather than through {@see CapabilityProbe} for the
 * reason {@see PanAtRestProbe} is: a register that empties on restart and a
 * register that will not accept a record are different failures with different
 * remedies, and grading either Partial because the other held would let
 * `composer compliance:check` pass over it — the gate fails on Unsatisfied, not
 * on Partial.
 *
 * WHAT IT CANNOT SEE, and the reason the control is narrowed in its own
 * requirement text: Article 33 is an obligation to NOTIFY a supervisory
 * authority. Pulsar does not know who that authority is, cannot reach it, and has
 * no way to observe that a notification was sent. What it observes is the half it
 * delivers — that a breach can be recorded, dated and produced inside the
 * deadline — and the deadline the profile imposes is printed beside it as
 * corroboration.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BreachNotificationProbe implements ControlProbeInterface
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
        return 'Whether an incident recorded through the live register came back intact with its '
            . 'timestamp, which register held it, and what notification deadline the profile '
            . 'imposes.';
    }

    #[Override]
    #[NoDiscard]
    public function requirement(): ControlRequirement
    {
        return ControlRequirement::of(
            required: [
                RequiredFact::essential(
                    ObservationId::IncidentRecordedAndRetained,
                    [
                        'Bind an IncidentReporterInterface the register can actually write '
                            . 'through: a FileIncidentReporter whose directory cannot be created '
                            . 'refuses every incident this deployment detects.',
                        'Read the evidence line for this fact: it names whether the record was '
                            . 'refused, lost, or came back altered, and how.',
                    ],
                    'no incident was recorded and read back when the register was asked to',
                ),
                RequiredFact::essential(
                    ObservationId::IncidentReporterResolved,
                    [
                        'Bind IncidentReporterInterface to FileIncidentReporter or another '
                            . 'register that persists; InMemoryIncidentReporter loses the register '
                            . 'on restart, and a deadline it cannot outlive cannot be evidenced.',
                        'Enable observability.audit.enabled so SecurityWiring has a durable '
                            . 'directory to put the register in beside the audit trail.',
                    ],
                    'the register does not survive the process that wrote to it',
                ),
            ],
            supporting: [
                ObservationId::BreachNotificationDeadlineSet,
                ObservationId::AuditSinkResolved,
            ],
            whenUnobserved: [
                'Re-run the report against the deployment itself, so the incident register that '
                    . 'receives real detections can be exercised rather than inferred.',
            ],
        );
    }
}
