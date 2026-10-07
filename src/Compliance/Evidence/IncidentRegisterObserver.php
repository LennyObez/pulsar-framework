<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;
use Random\Randomizer;
use Throwable;

use function bin2hex;
use function count;
use function hash_equals;
use function implode;
use function is_string;
use function sprintf;

/**
 * Whether an incident recorded now can be read back, with the clock a
 * notification deadline is measured from.
 *
 * WHY THIS EXISTS. `composer compliance:check` failed on a default installation
 * because GDPR Art 33 was claimed and not observed, and ADR-0046 left that
 * failure standing rather than configuring it away. The control rested on
 * {@see IncidentReporterInterface} RESOLVING to `FileIncidentReporter` — a
 * register that WOULD survive a restart, with nothing ever written to it. That is
 * ADR-0041's defect: the class is bound, and nothing has been recorded, dated or
 * retrieved. Art 33 gives 72 hours and NIS2 Art 23 gives 24, and a deadline is
 * measured from a timestamp on a record somebody can still find.
 *
 * WHAT IT RUNS, in order, all three against the live register:
 *
 *   1. record a synthetic incident — the register must return it with an id and
 *      a timestamp
 *   2. find it by that id — on the file register this re-reads the log from disk,
 *      so the record demonstrably left the process that wrote it
 *   3. compare what came back — severity, title, marker and the timestamp,
 *      because a register that returns a record with a different clock, or
 *      without the context an investigator needs, cannot evidence a deadline or
 *      what it was a deadline for
 *
 * THIS MEASUREMENT WRITES AND CANNOT TAKE IT BACK, which is the difference
 * between it and {@see TokenVaultObserver}, and it is deliberate rather than an
 * omission. {@see IncidentReporterInterface} has no removal, and it should not
 * grow one: a register whose entries can be deleted evidences nothing, and adding
 * a deletion seam to a shipped, append-only security log so that a compliance
 * check could tidy up after itself would damage the control to measure it.
 *
 * So one row is left per report run, and the cost is bounded on purpose:
 *
 *  - the severity is {@see IncidentSeverity::Low}, the lowest the vocabulary has,
 *    and that is load-bearing rather than polite:
 *    {@see \Pulsar\Compliance\Verification\BreachNotificationCheck} reads the
 *    register at HIGH and above and fails a deployment holding a reportable
 *    incident past its notification deadline. A probe row written at any higher
 *    severity would, hours later, fail the very check it exists to support — the
 *    measurement would have created the breach it reports on;
 *  - the title and source say what it is in the first words an operator reads,
 *    and the description says plainly that it describes no security event;
 *  - the source is {@see PROBE_SOURCE}, so every row this class ever wrote can be
 *    found and filtered with one string;
 *  - `compliance:report` is run by an operator or a pipeline, not by a request —
 *    the gatherer is bound as a lazy singleton for exactly that reason — so the
 *    growth is one line per report, not one per page view.
 *
 * And there is something on the other side of that trade, which is why it is
 * worth making: a register that accumulates a dated, retrievable self-test on
 * every compliance run is itself an artefact. It shows an assessor the register
 * was working on the dates the reports were produced, which is more than the
 * report's own sentence about it can show.
 *
 * WHAT IT CANNOT SEE. This runs in one process, so an `InMemoryIncidentReporter`
 * would pass all three subjects and be empty again on the next request. That is
 * why {@see ObservationId::IncidentReporterResolved} stays an ESSENTIAL fact in
 * {@see \Pulsar\Compliance\Probe\BreachNotificationProbe}: durability is a
 * question about which register answered, and no in-process measurement can ask
 * it.
 *
 * AND WHAT NO OBSERVER CAN. Art 33 is an obligation to NOTIFY a supervisory
 * authority. Pulsar does not know who that authority is, cannot reach it, and has
 * no way to observe that a notification was sent. What it can observe is the half
 * it delivers — that a breach can be recorded, dated, and produced again inside
 * the deadline — and the control's requirement text says so where a reader of the
 * report will see it.
 *
 * @see docs/adr/0065-an-incident-register-is-measured-by-recording-something.md
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class IncidentRegisterObserver
{
    /**
     * The source every record this check writes carries.
     *
     * Public because it is the string an operator filters the register with, and
     * because the row is permanent: a marker nobody can look up would make the
     * residue worse than it needs to be.
     */
    public const string PROBE_SOURCE = 'compliance.incident_register_probe';

    /** The title the row carries, first thing an operator reads. */
    private const string PROBE_TITLE = 'Compliance report self-test (not a security event)';

    /** The metadata key the run marker is written under. */
    private const string MARKER_KEY = 'probe_marker';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live incident register';

    /** Bytes of randomness in the run marker; 8 bytes = 16 hex characters. */
    private const int MARKER_BYTES = 8;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise the register, or report that there was none to exercise.
     *
     * @param IncidentReporterInterface|null $register The register that receives
     *        incidents from the threat-detection engine, the audit anomaly detector
     *        and the break-the-glass middleware, resolved by the composition root.
     *        Null when nothing answered the contract — which is a fact, not a pass
     */
    #[NoDiscard]
    public function observe(?IncidentReporterInterface $register): Observation
    {
        if ($register === null) {
            return Observation::measured(
                ObservationId::IncidentRecordedAndRetained,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No incident register is in service, so nothing was recorded and no breach '
                        . 'could be dated for a notification deadline.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::IncidentRecordedAndRetained,
            $this->exercise($register),
            self::class,
        );
    }

    /**
     * Record one incident and read it back.
     *
     * A throw from report() is a subject that RAN and failed, not a run that could
     * not happen: the register was called and it refused. A `FileIncidentReporter`
     * pointed at a directory it cannot create falls exactly there, and reporting
     * that the same way as "no register is bound" would send an operator to bind
     * the register they already have.
     */
    private function exercise(IncidentReporterInterface $register): Measurement
    {
        $marker = bin2hex($this->randomizer->getBytes(self::MARKER_BYTES));

        try {
            $recorded = $register->report(
                IncidentSeverity::Low,
                self::PROBE_TITLE,
                'Written by pulsar compliance:report to establish that this deployment records '
                    . 'an incident, dates it, and can produce it again. It describes no security '
                    . 'event and requires no response.',
                self::PROBE_SOURCE,
                [self::MARKER_KEY => $marker],
            );
        } catch (Throwable $failure) {
            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'record an incident',
                    sprintf(
                        'The register refused to record an incident: %s. Nothing this deployment '
                            . 'detects can be written down, so no notification deadline can start.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf(
                    'The incident register is bound but not usable: report() failed with %s.',
                    $failure->getMessage(),
                ),
            );
        }

        $results = [
            self::incidentIsDated($recorded),
            ...self::recordIsRetrievable($register, $recorded, $marker),
        ];

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * A record with no id cannot be found again, and one with no clock cannot
     * start a deadline.
     *
     * Both are checked here rather than assumed from the return type: the contract
     * says an implementation returns an incident, and says nothing about it being
     * one anybody can act on.
     */
    private static function incidentIsDated(IncidentInterface $recorded): ExecutedSubject
    {
        if ($recorded->id() === '') {
            return ExecutedSubject::failed(
                'the incident is recorded and dated',
                'The register accepted an incident and returned no identifier for it, so nothing '
                    . 'can retrieve the record it claims to have made.',
            );
        }

        if ($recorded->reportedAt()->getTimestamp() <= 0) {
            return ExecutedSubject::failed(
                'the incident is recorded and dated',
                'The register returned an incident with no usable timestamp, so the 72-hour '
                    . 'deadline Article 33 imposes has nothing to run from.',
            );
        }

        return ExecutedSubject::passed(
            'the incident is recorded and dated',
            sprintf(
                'The register accepted an incident, identified it as %s and dated it %s.',
                $recorded->id(),
                $recorded->reportedAt()->format('c'),
            ),
        );
    }

    /**
     * Read the record back and compare it, as two subjects rather than one.
     *
     * "The record is gone" and "the record came back changed" are different
     * failures with different causes — a log that was never flushed against one
     * that truncates fields — and an operator sent to the wrong one loses the time
     * the deadline was measuring.
     *
     * @return list<ExecutedSubject>
     */
    private static function recordIsRetrievable(
        IncidentReporterInterface $register,
        IncidentInterface $recorded,
        string $marker,
    ): array {
        try {
            $found = $register->find($recorded->id());
        } catch (Throwable $failure) {
            return [ExecutedSubject::failed(
                'the record can be produced again',
                sprintf(
                    'The register refused to return the incident it had just accepted: %s.',
                    $failure->getMessage(),
                ),
            )];
        }

        if ($found === null) {
            return [ExecutedSubject::failed(
                'the record can be produced again',
                'The register returned nothing for an incident it had just accepted, so the '
                    . 'record did not survive the call that created it and no breach it holds '
                    . 'could be produced within a deadline.',
            )];
        }

        return [
            ExecutedSubject::passed(
                'the record can be produced again',
                'The register returned the incident when asked for it by id, so the record '
                    . 'outlived the call that wrote it.',
            ),
            self::recordIsIntact($recorded, $found, $marker),
        ];
    }

    /**
     * What came back has to be what went in.
     *
     * The timestamp is compared to the second, which is the resolution the record
     * is stored at; the marker is compared because Article 33 asks a controller to
     * describe the NATURE of a breach and the categories of subjects affected, and
     * a register that keeps the row and drops the context around it cannot carry
     * that.
     */
    private static function recordIsIntact(
        IncidentInterface $recorded,
        IncidentInterface $found,
        string $marker,
    ): ExecutedSubject {
        $lost = [];

        if ($found->severity() !== $recorded->severity()) {
            $lost[] = 'the severity came back as ' . $found->severity()->value;
        }

        if (!hash_equals($recorded->title(), $found->title())) {
            $lost[] = 'the title came back changed';
        }

        if ($found->reportedAt()->getTimestamp() !== $recorded->reportedAt()->getTimestamp()) {
            $lost[] = sprintf(
                'the timestamp came back as %s instead of %s, so a deadline computed from the '
                    . 'stored record would be wrong',
                $found->reportedAt()->format('c'),
                $recorded->reportedAt()->format('c'),
            );
        }

        /** @var mixed $storedMarker */
        $storedMarker = $found->metadata()[self::MARKER_KEY] ?? null;

        if (!is_string($storedMarker) || !hash_equals($marker, $storedMarker)) {
            $lost[] = 'the metadata did not survive, so the context an investigator needs is '
                . 'not retained with the record';
        }

        return $lost === []
            ? ExecutedSubject::passed(
                'the record came back intact',
                sprintf(
                    'Severity, title, metadata and the timestamp %s all came back unchanged, so '
                        . 'the register holds what a notification would be written from.',
                    $found->reportedAt()->format('c'),
                ),
            )
            : ExecutedSubject::failed(
                'the record came back intact',
                'The register altered the incident it stored: ' . implode('; ', $lost) . '.',
            );
    }

    /**
     * The sentence the report prints, naming what failed when something did.
     *
     * @param list<ExecutedSubject> $results
     *
     * @return non-empty-string
     */
    private static function describe(array $results): string
    {
        $failed = [];

        foreach ($results as $result) {
            if (!$result->passed) {
                $failed[] = $result->name . ': ' . $result->detail;
            }
        }

        return $failed === []
            ? sprintf(
                'A synthetic incident was recorded through the live register, dated, and read '
                    . 'back by id unchanged, so a breach detected by this deployment can be '
                    . 'produced with the clock a notification deadline runs from. %d subject(s) '
                    . 'ran, and the record is retained under source "%s" — this register has no '
                    . 'removal, by design.',
                count($results),
                self::PROBE_SOURCE,
            )
            : 'The live incident register did not record and retain an incident — '
                . implode(' | ', $failed);
    }
}
