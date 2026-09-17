<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;

/**
 * How outcomes and grades are spelled in a rendered report.
 *
 * Presentation deliberately does not live on {@see ControlOutcome} or
 * {@see ObservationGrade}: those enums are the vocabulary a probe reasons in,
 * and a domain type that also knows its own column width is a type two things
 * can want to change for different reasons. Keeping the spellings here also
 * means the three renderers cannot drift into three different words for one
 * outcome, which for a compliance artefact would be a defect and not a cosmetic
 * one.
 */
#[Internal(reason: 'Presentation detail shared by the report renderers')]
final readonly class ReportVocabulary
{
    /**
     * Fixed-width marker for the printed report, so the outcome column aligns
     * and a gap is findable by eye in a long document.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public static function marker(ControlOutcome $outcome): string
    {
        return match ($outcome) {
            ControlOutcome::Unsatisfied => 'GAP ',
            ControlOutcome::Partial => 'PART',
            ControlOutcome::Satisfied => 'OK  ',
            ControlOutcome::NotApplicable => 'N/A ',
            ControlOutcome::OperatorResponsibility => 'OPER',
        };
    }

    /**
     * Grade label for the evidence column.
     *
     * Prefer {@see evidenceLabel()} when an observation is in hand: a fact whose
     * subject does not exist has a grade the reader must not be shown, because it
     * would read as an answer to a question nothing was there to answer.
     *
     * Five words, not four, and the fifth is load-bearing for a reader rather than
     * for the decision table: `available` says the platform offers a primitive and
     * nothing here was seen using it. It reads next to `resolved` — which says
     * which class is bound — and the two are not interchangeable in a document an
     * assessor signs.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public static function grade(ObservationGrade $grade): string
    {
        return match ($grade) {
            ObservationGrade::Measured => 'measured',
            ObservationGrade::Available => 'available',
            ObservationGrade::Resolved => 'resolved',
            ObservationGrade::Declared => 'declared',
            ObservationGrade::Asserted => 'asserted',
        };
    }

    /**
     * The label an evidence line carries, which is its grade unless the
     * deployment has no subject for the fact at all.
     *
     * "No subject" displaces the grade rather than sitting beside it, because the
     * grade answers "how was this obtained" and there was nothing to obtain. A
     * reader scanning the evidence column must be able to tell an enumeration that
     * came back empty from a measurement that came back negative; printing
     * `resolved` for both is how "there is no transport to encrypt" once read as a
     * transport that was checked.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public static function evidenceLabel(Observation $observation): string
    {
        return $observation->subjectExists ? self::grade($observation->grade) : 'no subject';
    }

    /**
     * Whether the fact holds, as the evidence table spells it.
     *
     * Three answers, not two: a fact with no subject is neither yes nor no, and
     * a table that offered only those two would have to lie in one direction.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public static function presence(Observation $observation): string
    {
        return match (true) {
            !$observation->subjectExists => 'n/a',
            $observation->present => 'yes',
            default => 'no',
        };
    }

    /**
     * The operator scope assertion a not-applicable finding rests on.
     *
     * Returned so the report can reproduce the config key and value under the
     * operator's name rather than printing a bare "not applicable" — the
     * difference between a scoping decision an assessor can falsify in one
     * question and a hiding place.
     */
    #[NoDiscard]
    public static function scopeAssertion(ControlFinding $finding): ?Observation
    {
        if ($finding->outcome !== ControlOutcome::NotApplicable) {
            return null;
        }

        foreach ($finding->evidence as $observation) {
            if ($observation->grade === ObservationGrade::Asserted) {
                return $observation;
            }
        }

        return null;
    }
}
