<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_values;

/**
 * The frozen set of facts a probe is allowed to reason about.
 *
 * This is deliberately NOT the container. A probe holding a container could
 * resolve anything, and the cheapest question a container answers — "can this
 * be constructed?" — is precisely the question whose answer ADR-0041 showed to
 * be worthless. Handing a probe a container would also make it a service
 * locator, which the project forbids outright.
 *
 * It is also not a config repository: a probe that could read config could
 * satisfy a control from a value the operator typed, which is the defect in its
 * general form.
 *
 * What a probe gets instead is a value object of already-gathered, already-
 * graded facts. Gathering happens once, in the composition root, by a collaborator
 * built through constructor injection; by the time any probe runs, the set is
 * closed and immutable. A probe cannot reach for anything nobody measured.
 *
 * THE CONSTRUCTOR WAS PUBLIC AND THAT WAS THE CHOKE POINT LEFT OPEN. It validated
 * totality — all {@see ObservationId} cases present — and recorded no provenance
 * whatsoever, so composing fifty-one observations from anywhere produced an
 * evidence set indistinguishable from a gathered one, and everything downstream
 * inherited its authority. {@see ControlFinding::assess()} and
 * {@see ProbeVerdict::reach()} are both public and both take this type; they are
 * safe only if this type cannot be forged, and it could be.
 *
 * It is now private, and {@see gathered()} refuses every caller that is not the
 * component that measures — see {@see MeasuringComponent}. Totality is still
 * checked in the constructor rather than in the factory, so that the Reflection
 * escape the tests use produces a TOTAL set or nothing: a fixture may say what it
 * likes about a deployment, but it may not leave a fact out and let a probe
 * conclude from an absence.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ControlEvidence
{
    use SealedValue;

    /** @var array<string, Observation> */
    private array $observations;

    /**
     * @throws IncompleteEvidenceException when any ObservationId was not gathered.
     *         Totality is enforced here rather than at the call site so that a
     *         gatherer which silently stopped producing a fact fails the report
     *         loudly, instead of every probe depending on it quietly passing.
     */
    private function __construct(Observation ...$observations)
    {
        $indexed = [];

        foreach ($observations as $observation) {
            $indexed[$observation->id->value] = $observation;
        }

        $missing = [];

        foreach (ObservationId::cases() as $case) {
            if (!array_key_exists($case->value, $indexed)) {
                $missing[] = $case->value;
            }
        }

        if ($missing !== []) {
            throw IncompleteEvidenceException::notGathered($missing);
        }

        $this->observations = $indexed;
    }

    /**
     * The facts the component that measures produced on one run.
     *
     * The single door, and it is not a widening of the old public constructor: it
     * refuses everyone the constructor used to admit. There is no second door
     * anywhere in the subsystem, because every {@see Observation} that could be
     * handed to it is sealed the same way.
     *
     * @throws IncompleteEvidenceException   when any ObservationId was not gathered
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function gathered(Observation ...$observations): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self(...$observations);
    }

    /**
     * The observation for a fact. Total by construction.
     *
     * @throws IncompleteEvidenceException never in practice; the constructor has
     *         already proved the set total. Kept so the return type can stay
     *         non-nullable without a probe ever seeing null and reading the
     *         absence of a measurement as a pass.
     */
    #[NoDiscard]
    public function observation(ObservationId $id): Observation
    {
        return $this->observations[$id->value]
            ?? throw IncompleteEvidenceException::notGathered([$id->value]);
    }

    /**
     * Every gathered fact, for the report's evidence appendix.
     *
     * @return list<Observation>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->observations);
    }
}
