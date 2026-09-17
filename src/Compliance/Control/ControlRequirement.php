<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * What a control needs, and how its facts combine. The whole of what a probe may say.
 *
 * A probe used to return a {@see ProbeVerdict}, which meant a probe chose the
 * outcome and chose which observations to cite for it. Adversarial review used
 * exactly that freedom: a probe could mint its own evidence and hand back
 * Satisfied, so nothing tied a verdict to the facts anyone gathered.
 *
 * A probe now returns this instead. It names {@see ObservationId}s — a closed
 * vocabulary, so a fact nobody gathers cannot be named — and says how each
 * relates to the control. It cannot name an outcome, because no outcome appears
 * anywhere in this type, and it cannot invent a fact, because it hands over ids
 * and never observations. {@see ProbeVerdict::reach()} then reads those ids out
 * of the evidence set THE ENGINE holds and applies one decision table.
 *
 * The table, and the two rules in it that came out of review:
 *
 *   scope asserted out of play, ABOUT THIS ESTATE . NotApplicable
 *   EVERY required fact has no subject here ....... NotApplicable (nothing to be about)
 *   all required present, one REQUIRED measured
 *     ON THE CONTROL'S OWN ESTATE ................. Satisfied
 *   all required present, none measured there ..... Unsatisfied  (claimed, unobserved)
 *   an essential fact missing ..................... Unsatisfied  (whatever else holds)
 *   other required missing, one measured there .... Partial
 *   other required missing, none measured there ... Unsatisfied
 *
 * The two clauses in capitals are the estate joins, and the estate belongs to the
 * CONTROL rather than to this type. A probe names facts; what those facts have to
 * be ABOUT is declared per control on {@see ControlDeclaration::probed()}, because
 * one probe serves controls about different estates — `DataErasureProbe` answers
 * CCPA 1798.105, which is about personal data, and SOC 2 C1.2, which is about
 * confidential information. A requirement carrying the estate would make those two
 * controls indistinguishable, which is how one scope assertion came to retire
 * both. See {@see ControlSubject}.
 *
 * "Measured" in that table used to read "measured or resolved", and the change is
 * not a tightening of a threshold — it is the removal of a second, weaker way to
 * pass. Resolved answers "which class is bound, and is it on the allow-list",
 * which is ADR-0041's defect one lookup deeper. Controls that were Satisfied on
 * resolved identity alone are Unsatisfied now and their findings say so in the
 * words the report prints: claimed, and not observed.
 *
 * A required fact with no subject — see {@see SubjectAbsence} — is dropped from
 * the decision entirely: it cannot prove the control and it is not counted
 * against it. Only when NO required fact has a subject does the control itself
 * become inapplicable; one live requirement beside a subjectless one still
 * decides the outcome, so a control cannot be excused by the absence of a
 * neighbour.
 *
 * Admissibility is judged over the REQUIRED facts only. It used to be judged over
 * required-plus-supporting, so a single admissible supporting fact — a corroborating
 * detail that decides nothing — could carry a control to Satisfied on its own.
 * Supporting facts are printed with the finding and never decide it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ControlRequirement
{
    use SealedValue;

    /**
     * @param non-empty-list<RequiredFact> $required
     * @param list<ObservationId>          $supporting
     * @param list<non-empty-string>       $whenUnobserved
     */
    private function __construct(
        public array $required,
        public array $supporting,
        public ?ObservationId $scope,
        public array $whenUnobserved,
    ) {}

    /**
     * @param list<RequiredFact>           $required   The facts the control rests on. A control
     *        that requires nothing is satisfied by nothing, which is the defect this design
     *        exists to make inexpressible. Typed as a plain list, not a non-empty one: this is
     *        public API, code outside this repository can call it, and the emptiness rule is
     *        therefore enforced below rather than only by a static analyser the caller may
     *        never run
     * @param list<ObservationId>          $supporting Facts printed with the finding that
     *        corroborate it and never decide it
     * @param ObservationId|null           $scope      The operator scope assertion that can put
     *        this control out of play. Only ever a claim about the DATA the deployment handles;
     *        enabling a framework IS the claim that it applies, so no framework can scope itself out
     * @param list<non-empty-string>       $whenUnobserved What to tell the operator when every
     *        required fact is configured and none was observed happening. Defaults to the union
     *        of the required facts' own remediations
     *
     * @throws InadmissibleEvidenceException when no required fact is named
     */
    #[NoDiscard]
    public static function of(
        array $required,
        array $supporting = [],
        ?ObservationId $scope = null,
        array $whenUnobserved = [],
    ): self {
        if ($required === []) {
            throw InadmissibleEvidenceException::forRequirementWithoutFacts();
        }

        return new self($required, $supporting, $scope, $whenUnobserved === []
            ? self::unionOfRemediations($required)
            : $whenUnobserved);
    }

    /**
     * @param non-empty-list<RequiredFact> $required
     *
     * @return list<non-empty-string>
     */
    private static function unionOfRemediations(array $required): array
    {
        $remediations = [];

        foreach ($required as $fact) {
            foreach ($fact->remediations as $remediation) {
                if (!in_array($remediation, $remediations, true)) {
                    $remediations[] = $remediation;
                }
            }
        }

        return $remediations;
    }
}
