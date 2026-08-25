<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when something claims to have measured or inspected nothing.
 *
 * {@see ObservationGrade::Measured} is the only grade that can carry a control to
 * Satisfied, so the material behind it is checked rather than trusted — and the
 * same check is kept over {@see ObservationGrade::Resolved}, which decides nothing
 * any more but is still printed for an assessor to read. A {@see Measurement} with
 * no executed subject and an {@see Inspection} whose population is empty are the
 * same mistake: a conclusion drawn over nothing, which reads in a report exactly
 * like a conclusion drawn over everything.
 *
 * A LogicException on purpose. Reaching here is a defect in the code that gathers
 * facts, never a property of the deployment being assessed, and printing it as a
 * compliance result would put a bug and a finding in the same column.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class UnmeasuredSubjectException extends LogicException
{
    /**
     * @param string $subject
     */
    #[NoDiscard]
    public static function nothingRan(string $subject): self
    {
        return new self(sprintf(
            'A measurement of "%s" completed without naming one subject that ran. Grade %s is '
                . 'reserved for behaviour that was exercised; a run with no executed subject is '
                . 'not a weak measurement, it is the absence of one. Report it with '
                . 'Measurement::couldNotRun(), which observes absent.',
            $subject,
            ObservationGrade::Measured->value,
        ));
    }

    /**
     * @param string $population
     */
    #[NoDiscard]
    public static function emptyPopulation(string $population): self
    {
        return new self(sprintf(
            'An inspection of "%s" was reported complete over an empty population. "Every member '
                . 'complies" is vacuously true of no members and reads in the report exactly like '
                . 'coverage of a real estate. Report it with Inspection::nothingToInspect(), which '
                . 'observes absent and says why.',
            $population,
        ));
    }
}
