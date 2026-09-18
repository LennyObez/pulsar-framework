<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether this deployment can restore itself.
 *
 * WHAT CHANGED, AND WHY IT IS NOT JUST "THE PRIMITIVE ARRIVED". This probe was
 * written against a framework that had no backup or restore primitive at all: it
 * asked for one by name, nothing answered, and NIST CSF RC.RP, SOC 2 A1.3 and
 * HIPAA §164.308(a)(7) reported the gap. `docs/compliance.md` recorded the
 * control as staying red "until the primitive exists".
 *
 * The primitive exists now — {@see \Pulsar\Resilience\Backup\BackupServiceInterface}
 * and its sealed archive driver — and that alone would have closed nothing. Had
 * this probe kept requiring {@see ObservationId::BackupPrimitiveResolved}, three
 * controls across three standards would now be Satisfied because a class name
 * resolves, which is ADR-0041's defect committed in the one control that had
 * never had a carrier. Resolved evidence cannot carry a control anyway — see
 * {@see \Pulsar\Compliance\Control\ObservationGrade::provesBehaviour()} — so
 * leaving the requirement as it stood would instead have made the control
 * permanently unsatisfiable, which is a different kind of dishonest.
 *
 * So what it requires is {@see ObservationId::BackupRoundTripVerified}: a
 * measurement in which the deployment's own service sealed an archive on the
 * deployment's own backup destination, read it back, refused a copy with one byte
 * changed, and gave the payload back byte for byte.
 * {@see \Pulsar\Compliance\Evidence\BackupRoundTripObserver} is what runs it.
 *
 * The resolved fact stays, as SUPPORTING. It is worth printing — an assessor
 * reading `BackupServiceInterface -> SealedArchiveBackupService` knows which
 * class to go and read — and it decides nothing.
 *
 * WHAT A PASS HERE STILL DOES NOT ESTABLISH, because the control's own text asks
 * for more than any in-process check can see: that an archive was taken recently,
 * that a copy of it exists in another failure domain, that anyone has restored
 * the deployment's REAL data, or that a restore fits the recovery window HIPAA's
 * 72 hours names. The round trip proves the mechanism works on this host with
 * this key. `pulsar backup:restore` into a scratch database, timed and recorded,
 * is what answers the rest, and `docs/backup.md` states that boundary in the same
 * table as what the framework ships.
 *
 * The control still cannot honestly be scoped out — no deployment can assert that
 * recovery does not apply to it — so a deployment that turns the primitive off, or
 * runs without a master key and gets no primitive bound, fails it. That is the
 * design working, not a gap.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class RecoveryCapabilityProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.recovery_capability';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether this deployment can take a sealed backup and restore it, exercised end to end.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::BackupRoundTripVerified,
        ];
    }

    /**
     * Which class answers the backup contract. Printed, never decisive.
     *
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::BackupPrimitiveResolved,
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
            'Set PULSAR_MASTER_KEY. Without it the backup wiring binds nothing rather than '
                . 'writing an unsealed archive, so there is no observed way to recover.',
            'Check that resilience.backup.enabled is on and that resilience.backup.destination '
                . 'is a directory this deployment can write to; the round trip writes a real '
                . 'archive there and removes it again.',
            'Read the failing subject named in the finding. "an altered archive is refused" '
                . 'failing means this deployment cannot tell a backup from one somebody edited; '
                . '"what went in comes back" failing means its archives do not reproduce what '
                . 'they were given.',
            'If recovery is discharged entirely by infrastructure outside the application, '
                . 'remove this framework from enabled_frameworks rather than leaving the control '
                . 'claimed and unobserved.',
        ];
    }
}
