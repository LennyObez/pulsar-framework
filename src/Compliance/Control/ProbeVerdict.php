<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function count;
use function implode;
use function in_array;
use function sprintf;

/**
 * A control's conclusion, with the observations it rests on.
 *
 * THE ONLY WAY TO OBTAIN ONE IS {@see reach()}, which takes a
 * {@see ControlRequirement} and the {@see ControlEvidence} the engine holds. The
 * constructor is private and so is every outcome-named factory below, so there
 * is no expression anywhere — in a probe, in a mapping, in a test — that states
 * an outcome. An outcome is a value computed from gathered facts or it does not
 * exist.
 *
 * That is a narrower contract than the one review defeated. Before, a probe
 * called `ProbeVerdict::satisfied($summary, $evidence)` with an evidence array of
 * its own choosing; the admissibility check ran, but over observations the probe
 * had supplied, and a probe that could also mint observations could satisfy it
 * with fabricated ones. Now the probe supplies ids and the engine supplies facts.
 *
 * The summary is generated here too, from the observations that actually decided
 * the verdict, and names them. It used to be a per-probe constant asserting facts
 * the evidence printed beside it could contradict.
 *
 * {@see reach()} stays public and takes the evidence set from its caller. That is
 * safe for exactly one reason, and it is worth naming because it was not true
 * before: {@see ControlEvidence} cannot be composed outside the component that
 * measures. Given facts somebody gathered, this method is a pure function of them
 * — there is no argument that prefers an outcome and no branch a caller can aim
 * at. Given facts nobody gathered, there is no call, because there is no such
 * value.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ProbeVerdict
{
    use SealedValue;

    /** The summary a control retired by an operator assertion carries. */
    private const string SCOPED_OUT = 'The deployment asserts the subject of this control is out of scope.';

    /**
     * @param list<Observation>      $evidence
     * @param list<non-empty-string> $remediations
     */
    private function __construct(
        public ControlOutcome $outcome,
        public string $summary,
        public array $evidence,
        public array $remediations = [],
    ) {}

    /**
     * Decide one control from what a probe requires, what the control regulates,
     * and what was gathered.
     *
     * Every observation cited comes out of $evidence, which the engine gathered
     * once for the whole run; the requirement contributes ids and prose only. A
     * probe therefore cannot cite a fact that was not gathered, cannot cite one
     * it made up, and cannot prefer an outcome to the one the table yields.
     *
     * $subject is the estate the CONTROL regulates, declared by the mapping author
     * on {@see ControlDeclaration::probed()} rather than by the probe — see that
     * method for why a probe naming its own estate would be self-certifying. It
     * adds two joins to the table above, and both are refusals rather than new
     * ways to pass:
     *
     *   THE PROOF JOIN. A fact may carry this control only if the control's estate
     *   covers the fact's. A measurement about another estate is still printed,
     *   still named in the finding, and decides nothing — because an answer about
     *   the PHP build is not an answer about the personal data GDPR Art 5(1)(f)
     *   protects, and recomputing HMACs over the framework's own compliance
     *   register is not an answer about the audit trail PCI Req 10.2 requires.
     *   {@see ObservationGrade} settled HOW a fact was obtained; this settles what
     *   it was obtained ABOUT, which is the half that was still missing.
     *
     *   THE SCOPE JOIN. An operator assertion retires a control only if what the
     *   operator put out of scope covers what the control regulates. `scope.
     *   processes_personal_data = false` no longer retires SOC 2 C1.2 and CC6.5:
     *   they are about confidential information, and an entity that processes no
     *   personal data still holds contracts and source code it has to dispose of.
     *
     * @throws IncompleteEvidenceException  when a required id was never gathered —
     *         a defect in the gatherer, surfaced rather than defaulted
     * @throws InadmissibleEvidenceException when the requirement's scope id is not
     *         an operator assertion, which would let code scope out its own control,
     *         when the assertion is about a different estate, or when a
     *         not-applicable verdict is offered a fact that still has a subject
     */
    #[NoDiscard]
    public static function reach(
        ControlRequirement $requirement,
        ControlEvidence $evidence,
        ControlSubject $subject,
    ): self {
        if ($requirement->scope !== null) {
            $scope = $evidence->observation($requirement->scope);

            if (!$scope->present) {
                // Whether the scope id is an operator assertion at all is a defect
                // check about the REQUIREMENT, so it is asked BEFORE the estate
                // join. Asking it after would let a probe that scopes out its own
                // control escape the check by naming an estate that happens not to
                // match — the guard would silently stop running instead of firing.
                // It is asked again inside notApplicable(), where it is a property
                // of the value; here it is a property of this path.
                if ($scope->grade !== ObservationGrade::Asserted) {
                    throw InadmissibleEvidenceException::forNotApplicable(self::SCOPED_OUT, $scope);
                }

                // The estate join, and the A6 repair. An assertion retires the
                // control only when the estate the operator put out of play
                // CONTAINS the estate the control regulates. The direction matters:
                // an operator who declares all data in transit out of play retires
                // a control about the database link, and an operator who declares
                // personal data out of play does not retire a control about
                // confidential information.
                if ($scope->id->subject()->covers($subject)) {
                    return self::notApplicable(self::SCOPED_OUT, $subject, $scope);
                }
            }
        }

        $required = [];
        $missing = [];
        $shortfall = [];
        $proof = [];
        $elsewhere = [];
        $subjectless = [];

        foreach ($requirement->required as $fact) {
            $observation = $evidence->observation($fact->id);
            $required[] = $observation;

            // A fact with no subject in this deployment decides nothing. It is not
            // proof — there was nothing to observe — and it is not a gap either, so
            // it is skipped rather than counted against a deployment that has no
            // such thing to get wrong. Reporting "there is no database transport to
            // encrypt" as a satisfied encryption control is the inversion this
            // branch exists to make unreachable.
            if (!$observation->subjectExists) {
                $subjectless[] = $observation;

                continue;
            }

            if (!$observation->present) {
                $missing[] = $fact;
                $shortfall[] = $fact->whenMissing === ''
                    ? $observation->cite()
                    : sprintf('%s (%s)', $fact->whenMissing, $observation->cite());

                continue;
            }

            if (!$observation->isAdmissibleAsProof()) {
                continue;
            }

            // Exercised, and came back as the control asks. The last question is
            // whether it was exercised on the estate this control is about. A fact
            // that fails here is NOT counted as missing — it is present, and telling
            // an operator to go and provide something they already have would be the
            // wrong instruction — but it cannot carry the control either. It is held
            // aside so the finding can say exactly that.
            if ($subject->covers($observation->id->subject())) {
                $proof[] = $observation;

                continue;
            }

            $elsewhere[] = $observation;
        }

        $supporting = array_map(
            static fn(ObservationId $id): Observation => $evidence->observation($id),
            $requirement->supporting,
        );

        $all = [...$required, ...$supporting];

        // Every requirement was subjectless, so there is nothing in this deployment
        // for the control to be about. That is not a pass and not a failure: it
        // leaves the coverage denominator entirely, exactly as an operator scope
        // assertion does, and the report reproduces what was enumerated and found
        // empty so an assessor can challenge it.
        if ($subjectless !== [] && count($subjectless) === count($required)) {
            return self::withoutSubject(
                self::absentSubjectSummary($subjectless),
                $required,
                $all,
            );
        }

        if ($missing === []) {
            return $proof === []
                ? self::unsatisfied(
                    self::claimedButUnobserved($required, $subject, $elsewhere),
                    $all,
                    self::unobservedRemediations(
                        $required,
                        $subject,
                        $elsewhere,
                        $requirement->whenUnobserved,
                    ),
                )
                : self::satisfied(self::observedSummary($proof, $supporting), $proof, $all, $subject);
        }

        $summary = 'Not observed: ' . implode(' | ', $shortfall);
        $remediations = self::remediationsOf($missing);

        return $proof !== [] && !self::anyEssential($missing)
            ? self::partial($summary, $proof, $all, $remediations, $subject)
            : self::unsatisfied($summary, $all, $remediations);
    }

    /**
     * The control is met, and behaviour was observed to say so.
     *
     * Private: an outcome is reached, never chosen. The admissibility bar is kept
     * here rather than only in {@see reach()} so that the invariant is a property
     * of the value, not of one call path — a second decision table added later
     * cannot quietly produce a Satisfied verdict resting on configuration.
     *
     * @param list<Observation> $proof    The REQUIRED observations that carried it. The bar is
     *        checked against these and never against $evidence: $evidence also holds the
     *        supporting facts, and an admissible supporting fact carrying a control on its own
     *        is precisely the defect this signature exists to prevent
     * @param list<Observation> $evidence Everything printed with the finding
     * @param ControlSubject    $subject  The estate the control regulates. Re-checked here
     *        rather than trusted from the decision table, for the reason the whole method
     *        exists: a second table added later must not be able to satisfy a control from a
     *        measurement of something else
     *
     * @throws InadmissibleEvidenceException when nothing required is present at
     *         grade Measured and about the estate the control regulates
     */
    #[NoDiscard]
    private static function satisfied(
        string $summary,
        array $proof,
        array $evidence,
        ControlSubject $subject,
    ): self {
        foreach ($proof as $observation) {
            if ($observation->isAdmissibleAsProof() && $subject->covers($observation->id->subject())) {
                return new self(ControlOutcome::Satisfied, $summary, $evidence);
            }
        }

        throw InadmissibleEvidenceException::forSatisfied($summary, $proof);
    }

    /**
     * Part of the control is observed and the remainder is named.
     *
     * Held to BOTH bars: admissible proof of the part claimed, and at least one
     * remediation, so the assessor is always told what is still open.
     *
     * @param list<Observation>      $proof        The REQUIRED observations that carried the
     *        part claimed; see {@see satisfied()} for why the bar is checked against these
     * @param list<Observation>      $evidence     Everything printed with the finding
     * @param list<non-empty-string> $remediations
     * @param ControlSubject         $subject      The estate the control regulates; see
     *        {@see satisfied()} for why the join is re-checked here
     *
     * @throws InadmissibleEvidenceException when nothing required supports the
     *         claim, or when no residual gap is named
     */
    #[NoDiscard]
    private static function partial(
        string $summary,
        array $proof,
        array $evidence,
        array $remediations,
        ControlSubject $subject,
    ): self {
        if ($remediations === []) {
            throw InadmissibleEvidenceException::forPartialWithoutRemediation($summary);
        }

        foreach ($proof as $observation) {
            if ($observation->isAdmissibleAsProof() && $subject->covers($observation->id->subject())) {
                return new self(ControlOutcome::Partial, $summary, $evidence, $remediations);
            }
        }

        throw InadmissibleEvidenceException::forPartial($summary, $proof);
    }

    /**
     * The mapping claims the control and the deployment does not show it.
     *
     * Deliberately admits any evidence, including a negative observation alone.
     * Requiring proof of an absence would make the failing verdict harder to
     * state than the passing one, and a system that is easier to pass than to
     * fail produces optimistic catalogues no matter who writes them.
     *
     * @param list<Observation>      $evidence
     * @param list<non-empty-string> $remediations
     *
     * @throws InadmissibleEvidenceException when the gap is reported without a
     *         way to close it
     */
    #[NoDiscard]
    private static function unsatisfied(string $summary, array $evidence, array $remediations): self
    {
        if ($remediations === []) {
            throw InadmissibleEvidenceException::forUnsatisfiedWithoutRemediation($summary);
        }

        return new self(ControlOutcome::Unsatisfied, $summary, $evidence, $remediations);
    }

    /**
     * The control does not apply to this deployment.
     *
     * A control that does not apply is not a failure — but a claim ABOUT THE DATA
     * a deployment handles is not something code may conclude on its own. No probe
     * can know that a deployment stores no cardholder data. So this route to N/A
     * is admissible ONLY on an operator scope assertion that says the control's
     * subject is out of scope, and the report reproduces that assertion with its
     * config key under the operator's name. An assessor can falsify it in one
     * question, which is the difference between a scoping decision and a hiding
     * place.
     *
     * The other route, {@see withoutSubject()}, needs no assertion because it
     * rests on an enumeration the framework performed itself: a deployment with no
     * database is a fact about the wiring, not about the business.
     *
     * AND THE ASSERTION HAS TO BE ABOUT THIS CONTROL. That was the missing half:
     * an assertion was admissible for any control whose probe happened to name it,
     * so one claim about personal data retired SOC 2's confidentiality criteria
     * along with the privacy ones. The estate join is checked here as a property of
     * the value, beside the grade check, for the reason {@see satisfied()} gives.
     *
     * @throws InadmissibleEvidenceException when $scope is not an Asserted
     *         observation, asserts the subject IS in scope, or is about an estate
     *         that does not contain the one this control regulates
     */
    #[NoDiscard]
    private static function notApplicable(string $summary, ControlSubject $subject, Observation $scope): self
    {
        if ($scope->grade !== ObservationGrade::Asserted || $scope->present) {
            throw InadmissibleEvidenceException::forNotApplicable($summary, $scope);
        }

        if (!$scope->id->subject()->covers($subject)) {
            throw InadmissibleEvidenceException::forUnrelatedScope($summary, $subject, $scope);
        }

        return new self(ControlOutcome::NotApplicable, $summary, [$scope]);
    }

    /**
     * The control has no subject in this deployment.
     *
     * The second and last route to NotApplicable, and the only one code may reach
     * on its own. It is defensible where the operator route is not, because it
     * rests on an enumeration rather than a claim: a {@see SubjectAbsence} refuses
     * to exist over a population with a member, so the observations offered here
     * were each built from an estate that was looked at and found empty.
     *
     * The bar is re-checked over EVERY required observation rather than trusted
     * from the decision table, for the reason {@see satisfied()} gives: one
     * subjectless fact beside a live requirement must never be able to retire the
     * live one. A control still has a subject if any single requirement does.
     *
     * @param list<Observation> $required The control's required facts, all of which
     *        must report no subject
     * @param list<Observation> $evidence Everything printed with the finding
     *
     * @throws InadmissibleEvidenceException when a required fact still has a subject
     */
    #[NoDiscard]
    private static function withoutSubject(string $summary, array $required, array $evidence): self
    {
        if ($required === []) {
            throw InadmissibleEvidenceException::forRequirementWithoutFacts();
        }

        foreach ($required as $observation) {
            if ($observation->subjectExists) {
                throw InadmissibleEvidenceException::forSubjectedNotApplicable($summary, $required);
            }
        }

        return new self(ControlOutcome::NotApplicable, $summary, $evidence);
    }

    /**
     * The not-applicable summary, generated from the enumerations that came back
     * empty.
     *
     * Names each one, so the reader is told what the assessor looked for rather
     * than being handed a bare "not applicable" — the difference between a
     * statement an assessor can challenge and a hole in the report.
     *
     * @param non-empty-list<Observation> $subjectless
     */
    private static function absentSubjectSummary(array $subjectless): string
    {
        return sprintf(
            'Not applicable: this deployment has no subject for %d required fact(s), and no '
                . 'other fact decides the control: %s',
            count($subjectless),
            implode(' | ', array_map(self::cited(...), $subjectless)),
        );
    }

    /**
     * The Satisfied summary, generated from the observations that decided it.
     *
     * It used to open with the constant 'Observed working, on %d required fact(s)'
     * and emit it whenever the proof list was non-empty — which, while
     * {@see ObservationGrade::Resolved} still proved behaviour, included verdicts
     * where nothing had been executed at all and the sentence was simply false.
     * Two things changed. Resolved no longer reaches this list, so everything in
     * it was exercised; and every fact is now printed with the grade it was
     * obtained at, so the sentence is checkable against the lines under it rather
     * than being taken on trust.
     *
     * Corroboration is listed separately, marked as not being proof, and no longer
     * filtered by admissibility: a present supporting fact is worth showing an
     * assessor whatever its grade, and its grade is printed beside it so nobody
     * has to guess which half of the finding decided anything.
     *
     * @param non-empty-list<Observation> $proof
     * @param list<Observation>           $supporting
     */
    private static function observedSummary(array $proof, array $supporting): string
    {
        $summary = sprintf(
            'Observed working: %d required fact(s) were exercised in this deployment and came '
                . 'back as the control requires — %s',
            count($proof),
            implode(' | ', array_map(self::cited(...), $proof)),
        );

        $corroboration = [];

        foreach ($supporting as $observation) {
            if ($observation->subjectExists && $observation->present) {
                $corroboration[] = self::cited($observation);
            }
        }

        return $corroboration === []
            ? $summary
            : $summary . '. Corroborated, and not proved, by: ' . implode(' | ', $corroboration);
    }

    /**
     * One evidence line as a summary cites it: the fact, what was seen, and HOW IT
     * WAS OBTAINED.
     *
     * The grade travels with every citation because it is the difference between
     * the two sentences this class can print. A reader who sees `(measured)`
     * beside a fact is reading evidence; a reader who sees `(resolved)` is reading
     * the name of a class that is wired, and one who sees `(available)` is reading
     * what the platform ships with — both context. A summary that named facts
     * without their grades made all three look alike, which is the whole defect
     * one line further out.
     */
    private static function cited(Observation $observation): string
    {
        return sprintf(
            '%s (%s)',
            $observation->cite(),
            $observation->subjectExists ? $observation->grade->value : 'no subject',
        );
    }

    /**
     * The summary for the case the whole design exists to catch: every requirement
     * of the control is present and none of it was exercised.
     *
     * Renamed from `configuredButUnobserved` when Resolved stopped proving
     * behaviour, because the sentence stopped being about configuration alone.
     * Most of what lands here now is resolved identity — the deployment has the
     * classes and nobody ran them — and calling that CONFIGURED would understate
     * it in one direction while calling it observed would overstate it in the
     * other. So the sentence says what is true of all of them: claimed, not
     * observed, and every fact printed with the grade that makes the distinction
     * checkable. It gained a third clause when
     * {@see ObservationGrade::Available} arrived, because a loaded extension is
     * neither wired nor configured and the sentence would otherwise have
     * described it as one of the two.
     *
     * NOTE WHAT THIS SENTENCE IS SPELLED AS. It is
     * {@see ControlOutcome::Unsatisfied}, so a control nobody measured is counted
     * in the same column as one that was measured and failed. That is a known gap
     * in the outcome vocabulary rather than a claim about the deployment, and it
     * is deferred to its own decision; nothing here can close it, because there is
     * no third column to put the finding in.
     *
     * WHEN SOMETHING WAS EXERCISED AND IT WAS THE WRONG ESTATE, the sentence has
     * to say so, and this is where a report keeps its information content instead
     * of dissolving into a page of identical gaps. "Claimed and not observed" is
     * true of PCI Req 10.2 on a deployment with a verified evidence chain, and it
     * is not the whole truth: something DID run, and what it interrogated was the
     * framework's own compliance register rather than the audit trail the control
     * regulates. An operator told only the first half will go looking for a
     * measurement that already exists.
     *
     * @param list<Observation> $required
     * @param list<Observation> $elsewhere Required facts that were exercised, came back as
     *        the control asks, and interrogated a different estate
     */
    private static function claimedButUnobserved(
        array $required,
        ControlSubject $subject,
        array $elsewhere,
    ): string {
        $subjected = [];

        foreach ($required as $observation) {
            // A fact with no subject was not claimed and not observed; it had
            // nothing to be about, and folding it into this sentence would read as
            // though the deployment had asked for something and not delivered it.
            if ($observation->subjectExists) {
                $subjected[] = self::cited($observation);
            }
        }

        return sprintf(
            'Claimed and not observed: every requirement of this control is present in this '
                . 'deployment and none of it was exercised ON %s, so what can be shown is which '
                . 'classes are wired, what the platform offers and what the configuration '
                . 'asks for — never that the control runs: %s%s',
            $subject->value,
            implode(' | ', $subjected),
            self::exercisedElsewhere($subject, $elsewhere),
        );
    }

    /**
     * The clause naming a measurement that ran and was about something else.
     *
     * Empty for the ordinary case, where nothing required was measured at all.
     *
     * @param list<Observation> $elsewhere
     */
    private static function exercisedElsewhere(ControlSubject $subject, array $elsewhere): string
    {
        if ($elsewhere === []) {
            return '';
        }

        $lines = [];

        foreach ($elsewhere as $observation) {
            $lines[] = sprintf(
                '%s was exercised and interrogated %s',
                $observation->id->value,
                $observation->id->subject()->value,
            );
        }

        return sprintf(
            '. Something did run, and not on this control\'s estate: %s, while this control '
                . 'regulates %s. A measurement of one estate is not evidence about another, '
                . 'however strong it is.',
            implode(' | ', $lines),
            $subject->value,
        );
    }

    /**
     * What to tell an operator when every requirement is present and none of it
     * was exercised.
     *
     * A required fact's own remediation answers a different question — it says
     * what to do when the fact is ABSENT, and every one of them here reads "bind
     * X", "install Y" or "configure Z" about something already bound, already
     * installed and already configured.
     * Printed alone under a claimed-and-not-observed finding, that is advice to
     * redo work that is done, and an operator who follows it will change nothing
     * and see the same gap again.
     *
     * So the real remediation is generated first, from the facts themselves: it
     * names them, names the grade each was obtained at, and says the only two
     * things that close the control. The declared remediations follow it, because
     * they are still worth reading — an implementation on the accept list is not
     * the same as one nobody assessed, and the evidence lines say which.
     *
     * A required fact that WAS exercised, on another estate, gets its own line for
     * the same reason: telling an operator to "bind an audit sink that persists"
     * when the audit sink is bound and the evidence chain verified sends them to
     * re-do work that is done. What closes that control is an observer of the
     * estate the control names, and the line says which estate that is.
     *
     * @param list<Observation>      $required
     * @param list<Observation>      $elsewhere Exercised, and about a different estate
     * @param list<non-empty-string> $declared
     *
     * @return list<non-empty-string>
     */
    private static function unobservedRemediations(
        array $required,
        ControlSubject $subject,
        array $elsewhere,
        array $declared,
    ): array {
        $unexercised = [];

        foreach ($required as $observation) {
            if ($observation->subjectExists && !$observation->grade->provesBehaviour()) {
                $unexercised[] = sprintf('%s (%s)', $observation->id->value, $observation->grade->value);
            }
        }

        $lines = [];

        if ($unexercised !== []) {
            $lines[] = sprintf(
                'Nothing exercised %s. Each is present, and each was obtained in a way that '
                    . 'records what is wired, what the platform offers or what was configured '
                    . 'rather than what ran, so none of them can carry the control. Closing it '
                    . 'needs an observer that puts the subsystem through its work — as '
                    . 'TokenVaultObserver does for the token vault — or this framework removed '
                    . 'from enabled_frameworks in config/compliance.php.',
                implode(', ', $unexercised),
            );
        }

        foreach ($elsewhere as $observation) {
            $lines[] = sprintf(
                '%s was exercised, and it interrogated %s while this control regulates %s. Do '
                    . 'not re-do that work; it is done. Closing this control needs an observer '
                    . 'that exercises %s itself.',
                $observation->id->value,
                $observation->id->subject()->value,
                $subject->value,
                $subject->value,
            );
        }

        return $lines === [] ? $declared : [...$lines, ...$declared];
    }

    /**
     * @param non-empty-list<RequiredFact> $missing
     *
     * @return list<non-empty-string>
     */
    private static function remediationsOf(array $missing): array
    {
        $remediations = [];

        foreach ($missing as $fact) {
            foreach ($fact->remediations as $remediation) {
                if (!in_array($remediation, $remediations, true)) {
                    $remediations[] = $remediation;
                }
            }
        }

        return $remediations;
    }

    /**
     * @param non-empty-list<RequiredFact> $missing
     */
    private static function anyEssential(array $missing): bool
    {
        foreach ($missing as $fact) {
            if ($fact->essential) {
                return true;
            }
        }

        return false;
    }
}
