<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use NoDiscard;
use Override;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\EvidenceSourceInterface;

use function array_values;
use function in_array;
use function sprintf;
use function str_starts_with;

/**
 * Builds a complete, deliberately extreme evidence set.
 *
 * {@see ControlEvidence} is total over the whole {@see ObservationId}
 * vocabulary, so a test cannot hand a probe three facts and hope: it must state
 * a position on every one. That is the point, and it is why the two fixtures
 * here are "everything observed working" and "nothing observed at all" rather
 * than a hand-picked middle — the middle is where a test starts agreeing with
 * whatever the implementation happens to do.
 */
final readonly class EvidenceFixture implements EvidenceSourceInterface
{
    /** Value prefix that marks an operator scope assertion in the vocabulary. */
    private const string SCOPE_PREFIX = 'scope_';

    private function __construct(
        private ControlEvidence $evidence,
    ) {}

    /**
     * A deployment where every fact holds, each at the strongest grade its kind
     * admits. Scope assertions are present, i.e. everything is IN scope: a
     * fixture that quietly scoped subjects out would let a control reach
     * NotApplicable and be counted as "not a gap" for the wrong reason.
     */
    #[NoDiscard]
    public static function everythingObserved(): self
    {
        return new self(self::build(true));
    }

    /**
     * A deployment where nothing was observed. Scope assertions stay present so
     * that a control failing here fails because the deployment does not show it,
     * not because the fixture scoped it out.
     */
    #[NoDiscard]
    public static function nothingObserved(): self
    {
        return new self(self::build(false));
    }

    /**
     * Everything observed but one named fact, for the middle a control reaches
     * when part of it holds and the rest does not.
     *
     * Named rather than picked at random: a fixture that quietly withheld a
     * different fact each run would make a Partial verdict a coincidence.
     */
    #[NoDiscard]
    public static function everythingObservedExcept(ObservationId $absent): self
    {
        return new self(self::build(true, $absent));
    }

    /**
     * Everything observed, except that the deployment has NO SUBJECT for the named
     * facts — no database to encrypt a transport for, and so on.
     *
     * The third state, which the two fixtures above cannot express: a deployment
     * that has the thing and got it wrong and a deployment that does not have the
     * thing must not be describable by the same object, or the tests inherit
     * exactly the confusion the production code is being cured of.
     */
    #[NoDiscard]
    public static function everythingObservedWithNoSubjectFor(ObservationId ...$subjectless): self
    {
        return new self(self::build(true, null, array_values($subjectless)));
    }

    /**
     * Nothing observed, and no subject for the named facts either.
     */
    #[NoDiscard]
    public static function nothingObservedWithNoSubjectFor(ObservationId ...$subjectless): self
    {
        return new self(self::build(false, null, array_values($subjectless)));
    }

    #[Override]
    public function gather(): ControlEvidence
    {
        return $this->evidence;
    }

    /**
     * @param list<ObservationId> $subjectless
     */
    private static function build(bool $present, ?ObservationId $absent = null, array $subjectless = []): ControlEvidence
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            if (in_array($id, $subjectless, true)) {
                $observations[] = SyntheticObservation::withoutSubject(
                    $id,
                    sprintf('test fixture: this deployment has no %s at all', $id->value),
                );

                continue;
            }

            $grade = self::grade($id);
            // A scope assertion is always "in scope" here; see the class docblock.
            $holds = ($grade === ObservationGrade::Asserted || $present) && $id !== $absent;

            $observations[] = SyntheticObservation::of(
                $id,
                $grade,
                $holds,
                sprintf('test fixture: %s was %s', $id->value, $holds ? 'found' : 'not found'),
            );
        }

        return ReflectedVocabulary::evidence(...$observations);
    }

    /**
     * Grade each fact the way a real gatherer would.
     *
     * Scope facts are recognised by their `scope_` value prefix rather than by
     * listing the cases: the vocabulary grows as probes need new facts, and a
     * fixture that named each case would silently mis-grade every case added
     * after it was written, which is the failure mode of a test that agrees with
     * whatever the implementation happens to be.
     */
    private static function grade(ObservationId $id): ObservationGrade
    {
        return str_starts_with($id->value, self::SCOPE_PREFIX)
            ? ObservationGrade::Asserted
            : ObservationGrade::Measured;
    }
}
