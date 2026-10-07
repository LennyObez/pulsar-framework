<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function count;

/**
 * The record of an estate that was enumerated and holds nothing this fact could
 * be about.
 *
 * The material behind {@see Observation::noSubject()}, and the answer to a
 * question the first version of this vocabulary could not express. It had two
 * states — the desirable thing was found, or it was not — and so a deployment
 * with NO DATABASE AT ALL had to be filed under one of them. It was filed under
 * the first: {@see \Pulsar\Compliance\Evidence\DatabaseTlsObserver} reported
 * "there is no transport to encrypt" as `present: true`, and a deployment that
 * encrypts nothing carried PCI Req 2.3 to Satisfied on it. Absence had been
 * spelled as presence because presence was the only spelling available.
 *
 * A third state fixes that, and it must be a state rather than a flag a caller
 * sets. So this class takes the POPULATION the assessor enumerated and refuses
 * to exist if that population has a member: an absence is claimable only over an
 * estate actually looked at and actually found empty. Marking a subject absent
 * while subjects are standing there throws, which is the inverse mistake and the
 * more dangerous one — it would let an observer retire a real requirement by
 * calling it inapplicable.
 *
 * What the resulting observation does downstream: nothing, deliberately. It is
 * not present, so it can never prove a control; and it is not a gap either, so
 * it is excluded from the decision rather than counted against the deployment.
 * A control ALL of whose required facts are subjectless reaches
 * {@see ControlOutcome::NotApplicable} — not a failure, not a pass, and out of
 * the coverage denominator. See {@see ProbeVerdict::reach()}.
 *
 * Review pointed out that the docblock above warns about an attack the class then
 * permitted: an empty array is trivial to write, so `noneIn('the database', [],
 * ...)` retired a real requirement by declaring it inapplicable. The population
 * check cannot tell a genuine empty enumeration from a literal `[]`, and no
 * signature can. What closes it is that {@see noneIn()} refuses every caller
 * outside `src/Compliance/Evidence/`, where the enumerations are actually
 * performed — see {@see MeasuringComponent}. The constructor is private and the
 * value refuses deserialization, cloning and `var_export`; see {@see SealedValue}.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SubjectAbsence
{
    use SealedValue;

    /**
     * @param string           $subject    What the control would have been about
     * @param non-empty-string $detail     Why this deployment has none, in the assessor's
     *                                     words; printed in the report in place of a grade
     * @param int              $population How many members the enumeration returned. Not
     *                                     stored: it exists so the refusal below is a
     *                                     property of the value rather than of one factory
     */
    private function __construct(
        public string $subject,
        public string $detail,
        int $population,
    ) {
        // Checked here rather than only in noneIn(), so that the refusal is a
        // property of the value and not of one call path. A second factory added
        // later, and the Reflection escape the tests use, both meet the same bar:
        // an absence is claimable only over an estate that was looked at and found
        // empty.
        if ($population !== 0) {
            throw InadmissibleEvidenceException::forPopulatedAbsence($subject, $population);
        }
    }

    /**
     * The population was enumerated and holds no member this fact could be about.
     *
     * @param string           $subject    What was looked for, as a phrase
     * @param list<string>     $population What the enumeration returned. Typed as a plain
     *        list rather than an empty one: this is public API, code outside this repository
     *        can call it, and the emptiness rule is therefore enforced below rather than only
     *        by a static analyser the caller may never run
     * @param non-empty-string $detail     Why there is none, and what that means for the
     *        control — stated so a reader is never left to infer that the check just did
     *        not run
     *
     * @throws InadmissibleEvidenceException when the population has a member, which
     *         would make this a claim that a subject standing in front of the assessor
     *         does not exist — or when the caller is not the component that measures
     *         this deployment, which is the same attack with the population left out
     */
    #[NoDiscard]
    public static function noneIn(string $subject, array $population, string $detail): self
    {
        MeasuringComponent::assertProducing(self::class, __FUNCTION__);

        return new self($subject, $detail, count($population));
    }
}
