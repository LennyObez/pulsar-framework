<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when something claims to have measured, inspected or found nothing.
 *
 * {@see ObservationGrade::Measured} is the only grade that can carry a control to
 * Satisfied, so the material behind it is checked rather than trusted — and the
 * same check is kept over {@see ObservationGrade::Resolved} and
 * {@see ObservationGrade::Available}, which decide nothing any more but are still
 * printed for an assessor to read. A {@see Measurement} with no executed subject,
 * an {@see Inspection} whose population is empty and a {@see PlatformCapability}
 * that names no primitive are the same mistake: a conclusion drawn over nothing,
 * which reads in a report exactly like a conclusion drawn over everything.
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

    /**
     * @param string $capability
     */
    #[NoDiscard]
    public static function nothingNamed(string $capability): self
    {
        return new self(sprintf(
            'The platform was reported as offering "%s" without naming one primitive that '
                . 'answered. Grade %s already claims nothing was exercised; a capability that '
                . 'additionally names nothing found claims nothing at all, and prints in the '
                . 'report exactly like a platform that was inspected. Report it with '
                . 'PlatformCapability::absent() or ::notInspected(), which observe absent.',
            $capability,
            ObservationGrade::Available->value,
        ));
    }
}
