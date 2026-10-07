<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use NoDiscard;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Builds one synthetic observation at a chosen grade, through
 * {@see ReflectedVocabulary} — an acknowledged escape from the seal, never a
 * supported way to produce evidence.
 *
 * It takes a grade and a `present` flag, which is exactly what
 * {@see Observation} refuses to take. An earlier version of this class insisted
 * that this was "not a way around that", because every branch went through a real
 * named constructor with real material. That claim was true and it was also the
 * problem: if a test could reach grade Measured by composing public factories,
 * then so could a probe, a mapping, an extension or an application, and three
 * reviews defeated the subsystem doing exactly that. The factories are sealed now,
 * this class can no longer call them, and it says plainly what it does instead.
 *
 * What a test buys is the ability to say "every fact, at grade Declared" without
 * spelling out forty constructions — which is what makes
 * {@see \Pulsar\Tests\Unit\Compliance\Probe\ProbeAdmissibilityTest} able to hold
 * every probe in the tree to the rule, against deployments no gatherer could
 * produce on demand.
 */
final readonly class SyntheticObservation
{
    /**
     * @param non-empty-string $detail
     */
    #[NoDiscard]
    public static function of(
        ObservationId $id,
        ObservationGrade $grade,
        bool $present,
        string $detail,
    ): Observation {
        return ReflectedVocabulary::observation($id, $grade, $present, $detail, self::class);
    }

    /**
     * A fact this deployment has no subject for.
     *
     * Separate from {@see of()} rather than a third value of `$present`, because
     * it is a third state and not a third value: an observation with no subject
     * proves nothing AND accuses nothing, and a fixture that spelled it as a
     * boolean would be unable to describe the case the production code now has to
     * handle.
     *
     * Graded {@see ObservationGrade::Resolved} for the same reason
     * {@see Observation::noSubject()} grades it that way: what was read is the
     * estate the deployment will actually serve.
     *
     * @param non-empty-string $detail
     */
    #[NoDiscard]
    public static function withoutSubject(ObservationId $id, string $detail): Observation
    {
        return ReflectedVocabulary::observation(
            $id,
            ObservationGrade::Resolved,
            false,
            $detail,
            self::class,
            subjectExists: false,
        );
    }
}
