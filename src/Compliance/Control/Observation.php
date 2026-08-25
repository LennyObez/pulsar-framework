<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * One fact about the running deployment, with its provenance.
 *
 * THE CONSTRUCTOR IS PRIVATE, AND EVERY NAMED CONSTRUCTOR REFUSES A CALLER THAT
 * IS NOT MEASURING. Those are two different statements and the subsystem needed
 * both, because three earlier versions had only the first.
 *
 * The first version was defeated by four lines: a probe called
 * `new Observation(AuditChainVerified, ObservationGrade::Measured, present: true,
 * detail: 'Implemented.', observedBy: self::class)` and the strongest grade in
 * the vocabulary was available to anyone who could type it. A grade that a caller
 * supplies is not a grade, it is a wish with a type. So the constructor went
 * private and the named constructors below took MATERIAL instead — a
 * {@see Measurement}, a {@see ContractResolution}, an {@see Inspection} — each
 * deriving the grade and the presence flag from what it was given.
 *
 * That was defeated too, and by less: the material types were public API with
 * public factories, so `Observation::measured($id, Measurement::completed('x',
 * [ExecutedSubject::passed('x', 'Implemented.')], 'Implemented.'), self::class)`
 * is one expression, uses nothing but documented `#[Api]` seams, and produces the
 * strongest evidence in the system about a deployment nobody looked at. Narrowing
 * signatures moves the lie one call deeper; it never removes it, because the code
 * that measures must ultimately be able to SAY what it saw, and the same words
 * are available to code that saw nothing.
 *
 * What separates the two is not the words. It is WHO IS SPEAKING. So every
 * factory below asks {@see MeasuringComponent} whether its caller is compiled
 * from `src/Compliance/Evidence/`, and refuses otherwise — a probe cannot produce
 * a fact, a mapping cannot, an extension cannot, an application cannot, a test
 * cannot. Read that class for what the seal does not stop, which is Reflection,
 * and why that escape is deliberate.
 *
 * The factories and what each derives:
 *
 *  - {@see measured()}  takes a {@see Measurement}, which cannot exist without
 *                       naming at least one subject that ran. The only grade that
 *                       proves behaviour.
 *  - {@see resolved()}  takes a {@see ContractResolution}, which reads the class
 *                       that answered and matches it against an accept list.
 *                       CONTEXT, not proof; see {@see ObservationGrade::provesBehaviour()}.
 *  - {@see inspected()} takes an {@see Inspection}, which enumerates a live
 *                       population and refuses to conclude over an empty one.
 *                       Also context.
 *  - {@see noSubject()} takes a {@see SubjectAbsence}, which cannot exist over a
 *                       population that has a member.
 *  - the `declared*` and `asserted*` pairs record a config read and an operator
 *    claim. Presence there is what was read or claimed rather than something
 *    derived — and it is harmless, because neither grade
 *    {@see ObservationGrade::provesBehaviour()}, so neither can carry a control
 *    to Satisfied however it is spelled.
 *
 * PRESENCE HAS THREE STATES, NOT TWO. The first version had two, and a
 * deployment with no database at all therefore had to be filed under one of
 * them: `DatabaseTlsObserver` filed "there is no transport to encrypt" as
 * `present: true`, and a deployment that encrypts nothing carried PCI Req 2.3
 * on it. `$subjectExists` is the third state. It is false only through
 * {@see noSubject()}, it forces `$present` false with it, and downstream it
 * decides nothing in either direction — see {@see ProbeVerdict::reach()}.
 *
 * The residual freedom, stated plainly: whoever gathers facts must ultimately
 * say what the deployment did, and no type can check that against reality. What
 * is closed is who may be that whoever, and everything downstream — a probe
 * cannot mint one of these into a verdict, because {@see ProbeVerdict} is
 * computed by the engine from the evidence set the engine holds. See
 * {@see ControlRequirement}. The value also refuses deserialization, cloning and
 * `var_export` round-tripping; see {@see SealedValue}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class Observation
{
    use SealedValue;

    /**
     * @param ObservationId    $id            The fact, from the closed vocabulary
     * @param ObservationGrade $grade         How it was obtained; decides what it may support
     * @param bool             $present       Whether the DESIRABLE state was found to hold.
     *                                        Uniform across the whole vocabulary — see the
     *                                        polarity note on {@see ObservationId}
     * @param non-empty-string $detail        What was actually seen, in the assessor's words —
     *                                        stated for BOTH outcomes, so a negative
     *                                        observation names what stood there instead
     * @param class-string     $observedBy    The class that produced it; printed in the report
     * @param bool             $subjectExists Whether this deployment has anything the fact
     *                                        could be about. False only through
     *                                        {@see noSubject()}, which passes `present: false`
     *                                        with it, so "no subject" can never be read as a
     *                                        control that holds
     */
    private function __construct(
        public ObservationId $id,
        public ObservationGrade $grade,
        public bool $present,
        public string $detail,
        public string $observedBy,
        public bool $subjectExists = true,
    ) {}

    /**
     * A fact established by running something.
     *
     * Grade {@see ObservationGrade::Measured}, and present only when every subject
     * that ran returned the desirable answer. A {@see Measurement} that could not
     * run observes absent, so a missing runner never reads as a clean result.
     *
     * @param class-string $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function measured(ObservationId $id, Measurement $measurement, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self(
            $id,
            ObservationGrade::Measured,
            $measurement->succeeded(),
            $measurement->detail,
            $observedBy,
        );
    }

    /**
     * A fact established by naming the class that will serve requests.
     *
     * Grade {@see ObservationGrade::Resolved}, and present only when that class is
     * one this release accepts as discharging the contract. The detail is the
     * resolution's own, so the prose cannot drift from the class name it describes.
     *
     * PRESENT IS NOT PROOF HERE. Resolved records which class answered, which is
     * "the class is bound" — the claim ADR-0041 showed to be worthless — and it
     * stopped proving behaviour when {@see ObservationGrade::provesBehaviour()}
     * was narrowed to Measured. A control resting only on facts like this one is
     * now reported as claimed and not observed.
     *
     * @param class-string $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function resolved(ObservationId $id, ContractResolution $resolution, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self(
            $id,
            ObservationGrade::Resolved,
            $resolution->discharged(),
            $resolution->detail(),
            $observedBy,
        );
    }

    /**
     * A fact established by enumerating a live population and judging its members.
     *
     * Grade {@see ObservationGrade::Resolved}: what was read is the estate the
     * deployment will actually serve — its routes, its booted extensions, its
     * satisfied optional bindings — not a setting expressing an intention. Better
     * than a config read and still not behaviour: an enumeration says what is
     * there, never that it works. It cannot carry a control; see
     * {@see ObservationGrade::provesBehaviour()}.
     *
     * @param class-string $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function inspected(ObservationId $id, Inspection $inspection, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self(
            $id,
            ObservationGrade::Resolved,
            $inspection->holds,
            $inspection->detail,
            $observedBy,
        );
    }

    /**
     * This deployment has nothing the fact could be about.
     *
     * Not a pass and not a gap. The estate was enumerated — a {@see SubjectAbsence}
     * cannot be built over a population that has a member — and it holds no subject
     * for this control, so the fact decides nothing in either direction.
     *
     * Graded {@see ObservationGrade::Resolved} because what was read is the estate
     * the deployment will actually serve, exactly as {@see inspected()} is; the
     * grade is moot for admissibility either way, since `present` is false and
     * {@see isAdmissibleAsProof()} needs both.
     *
     * @param class-string $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function noSubject(ObservationId $id, SubjectAbsence $absence, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self(
            $id,
            ObservationGrade::Resolved,
            false,
            $absence->detail,
            $observedBy,
            false,
        );
    }

    /**
     * A configuration value was read and it meets the requirement.
     *
     * Grade {@see ObservationGrade::Declared}: it records what was requested, never
     * what happened, and can never on its own carry a control to Satisfied.
     *
     * @param non-empty-string $detail
     * @param class-string     $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function declaredMet(ObservationId $id, string $detail, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($id, ObservationGrade::Declared, true, $detail, $observedBy);
    }

    /**
     * A configuration value was read and it does not meet the requirement — or
     * the setting that should have carried it was never produced at all.
     *
     * @param non-empty-string $detail
     * @param class-string     $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function declaredUnmet(ObservationId $id, string $detail, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($id, ObservationGrade::Declared, false, $detail, $observedBy);
    }

    /**
     * The operator asserts the subject of this fact IS in scope for the deployment.
     *
     * Grade {@see ObservationGrade::Asserted}. Silence means in scope, so this is
     * also what an operator who said nothing gets.
     *
     * @param non-empty-string $detail     The config key and value, reproduced verbatim
     * @param class-string     $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function assertedInScope(ObservationId $id, string $detail, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($id, ObservationGrade::Asserted, true, $detail, $observedBy);
    }

    /**
     * The operator asserts the subject of this fact is OUT of scope.
     *
     * The one thing an assertion is admissible for: carrying a control to
     * {@see ControlOutcome::NotApplicable}, reproduced in the report with the
     * config key that carried it, under the operator's name.
     *
     * @param non-empty-string $detail
     * @param class-string     $observedBy
     *
     * @throws InadmissibleEvidenceException when the caller is not the component
     *         that measures this deployment
     */
    #[NoDiscard]
    public static function assertedOutOfScope(ObservationId $id, string $detail, string $observedBy): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($id, ObservationGrade::Asserted, false, $detail, $observedBy);
    }

    /**
     * Whether this observation may support a Satisfied verdict.
     *
     * True for exactly one shape of fact: something that was exercised, and came
     * back with the desirable answer. Everything else in the vocabulary — a
     * config read, an operator claim, the identity of a bound class, the contents
     * of an enumerated estate — is printed in the report with its grade beside it
     * and decides nothing.
     */
    #[NoDiscard]
    public function isAdmissibleAsProof(): bool
    {
        return $this->present && $this->grade->provesBehaviour();
    }

    /**
     * The evidence line as a verdict summary cites it: the fact named, then what
     * was seen.
     *
     * @return non-empty-string
     */
    #[NoDiscard]
    public function cite(): string
    {
        return $this->id->value . ' — ' . $this->detail;
    }
}
