<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use LogicException;
use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function implode;
use function sprintf;

/**
 * Thrown when a verdict cannot be defended by the evidence behind it, or when a
 * control declares a requirement no verdict can be reached from.
 *
 * A LogicException, not a RuntimeException, and the distinction is the point:
 * Satisfied reached from configuration is a bug in the decision table or in the
 * probe that declared the requirement, not a finding about the deployment. The
 * report must never print it as a compliance result — an assessor reading
 * "satisfied" would have no way to tell it apart from an observed one.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class InadmissibleEvidenceException extends LogicException
{
    /**
     * @param list<Observation> $evidence
     */
    #[NoDiscard]
    public static function forSatisfied(string $summary, array $evidence): self
    {
        return new self(sprintf(
            'A verdict reached Satisfied ("%s") without one observation that is both present '
                . 'and graded Measured. Offered: %s. A control cannot be satisfied by '
                . 'configuration, by an operator assertion, by the identity of the class that '
                . 'answered a contract, or by nothing.',
            $summary,
            self::describe($evidence),
        ));
    }

    /**
     * @param list<Observation> $evidence
     */
    #[NoDiscard]
    public static function forPartial(string $summary, array $evidence): self
    {
        return new self(sprintf(
            'A verdict reached Partial ("%s") without one observation that is both present '
                . 'and graded Measured. Offered: %s. Partial claims that PART of the control was '
                . 'observed, so it needs proof of that part.',
            $summary,
            self::describe($evidence),
        ));
    }

    #[NoDiscard]
    public static function forNotApplicable(string $summary, Observation $scope): self
    {
        return new self(sprintf(
            'A verdict reached NotApplicable ("%s") from %s. Only an operator scope assertion '
                . '(grade Asserted) stating the control subject is OUT of scope can carry that '
                . 'outcome; no probe can know on its own that a deployment is out of scope.',
            $summary,
            self::describe([$scope]),
        ));
    }

    /**
     * A not-applicable verdict offered a required fact that HAS a subject.
     *
     * The second route to NotApplicable — every required fact reporting that this
     * deployment has no such subject — is checked here as a property of the value
     * rather than only in the decision table, for the same reason
     * {@see ProbeVerdict::satisfied()} is: a second table added later must not be
     * able to retire a live requirement by calling it inapplicable.
     *
     * @param list<Observation> $required
     */
    #[NoDiscard]
    public static function forSubjectedNotApplicable(string $summary, array $required): self
    {
        return new self(sprintf(
            'A verdict reached NotApplicable ("%s") while a required fact still has a subject '
                . 'in this deployment. Offered: %s. Subjectlessness excuses a control only when '
                . 'there is nothing left for it to be about; anything else is a gap being '
                . 'renamed.',
            $summary,
            self::describe($required),
        ));
    }

    /**
     * An absence claimed over an estate that has members.
     */
    #[NoDiscard]
    public static function forPopulatedAbsence(string $subject, int $found): self
    {
        return new self(sprintf(
            'An observation claims this deployment has no %s, but the enumeration it was built '
                . 'from returned %d. An absence is claimable only over a population that was '
                . 'looked at and found empty — otherwise a live requirement could be retired by '
                . 'declaring its subject imaginary.',
            $subject,
            $found,
        ));
    }

    #[NoDiscard]
    public static function forPartialWithoutRemediation(string $summary): self
    {
        return new self(sprintf(
            'A verdict reached Partial ("%s") without naming what is still open. Partial is the '
                . 'outcome most likely to become an escape hatch, so it must always tell the '
                . 'assessor what remains.',
            $summary,
        ));
    }

    #[NoDiscard]
    public static function forUnsatisfiedWithoutRemediation(string $summary): self
    {
        return new self(sprintf(
            'A verdict reached Unsatisfied ("%s") without a remediation. A reported gap that '
                . 'does not say how to close it is a complaint, not a finding.',
            $summary,
        ));
    }

    /**
     * A required fact declared with no way to close it.
     */
    #[NoDiscard]
    public static function forFactWithoutRemediation(ObservationId $id): self
    {
        return new self(sprintf(
            'The control requires %s but names nothing an operator could do when it is absent. '
                . 'Every required fact carries its own remediation, so no branch of the decision '
                . 'table can produce a gap that does not say how to close it.',
            $id->value,
        ));
    }

    /**
     * A control that requires nothing.
     */
    #[NoDiscard]
    public static function forRequirementWithoutFacts(): self
    {
        return new self(
            'A control requirement names no required fact. A control that requires nothing is '
                . 'satisfied by nothing observed, which is the defect this design exists to make '
                . 'inexpressible: it would report a pass on an empty evidence set.',
        );
    }

    /**
     * Something that is not the component that measures tried to produce a fact.
     *
     * The refusal names the file it came from rather than only the class, because
     * the seal is decided by file: see {@see MeasuringComponent} for why a
     * namespace would not be enough, and for the one escape that remains open.
     *
     * @param class-string $produced
     */
    #[NoDiscard]
    public static function forForeignProduction(string $produced, string $seam, ?string $caller): self
    {
        return new self(sprintf(
            '%s::%s() was called from %s, which is not the component that measures this '
                . 'deployment. Only the files under src/Compliance/Evidence/ may produce a '
                . 'compliance fact: a fact composed anywhere else records what its author '
                . 'wanted, and telling the two apart in a report is impossible after the fact.',
            $produced,
            $seam,
            $caller ?? 'a frame with no file',
        ));
    }

    /**
     * A value was reconstructed from a serialized payload instead of measured.
     *
     * @param class-string $type
     */
    #[NoDiscard]
    public static function forDeserialization(string $type, int $properties): self
    {
        return new self(sprintf(
            'A %s was reconstructed from %d serialized propert%s rather than produced by the '
                . 'component that measures. Deserialization sets every property without running '
                . 'a constructor, so it reopens the whole vocabulary to whoever can write a '
                . 'string; a compliance fact must come from an act, not from a payload.',
            $type,
            $properties,
            $properties === 1 ? 'y' : 'ies',
        ));
    }

    /**
     * A value was copied out of the run that produced it.
     *
     * @param class-string $type
     */
    #[NoDiscard]
    public static function forCloning(string $type): self
    {
        return new self(sprintf(
            'A %s was cloned. A compliance fact is tied to the run that produced it and the '
                . 'evidence set that holds it; a copy outlives both, and PHP keeps adding ways '
                . 'to modify a value while it is being copied.',
            $type,
        ));
    }

    /**
     * @param list<Observation> $evidence
     */
    private static function describe(array $evidence): string
    {
        if ($evidence === []) {
            return 'no observations at all';
        }

        return implode(', ', array_map(
            static fn(Observation $o): string => sprintf(
                '%s(%s, %s)',
                $o->id->value,
                $o->grade->value,
                match (true) {
                    !$o->subjectExists => 'no subject',
                    $o->present => 'present',
                    default => 'absent',
                },
            ),
            $evidence,
        ));
    }
}
