<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * One declared control, assessed against one gathered evidence set.
 *
 * THE CONSTRUCTOR IS PRIVATE, and the only public way in is {@see assess()},
 * which takes a declaration and the evidence set and computes everything else.
 *
 * The class shipped with a public constructor whose second parameter was a
 * {@see ControlOutcome}, and review defeated the entire subsystem with one
 * statement: `new ControlFinding($declaration, ControlOutcome::Satisfied,
 * 'Implemented.', probeId: 'probe.pan_at_rest')` — satisfied, zero evidence, the
 * status literal ADR-0041 removed from the mappings living one class over. There
 * is now no expression that produces a finding whose outcome its caller chose.
 *
 * A finding with an assessed outcome always carries the observations behind it:
 * they come from {@see ProbeVerdict::reach()}, which reads them out of the
 * evidence set rather than accepting them. The one finding with no evidence is
 * the operator-responsibility case, and that is not an assessment — its outcome
 * is fixed by the declaration carrying no probe, it can neither pass nor fail
 * the report, and it never counts toward coverage.
 *
 * {@see assess()} STAYS PUBLIC, and that is now defensible where it was not.
 * Review's objection was exact: it took the evidence set from its caller with no
 * proof of where it came from, so a caller who could compose a
 * {@see ControlEvidence} could aim a finding wherever it liked. That evidence set
 * is sealed now — its constructor is private and its one factory refuses anything
 * that is not the component that measures — so the only thing a caller can hand
 * this method is facts somebody gathered. Given real facts the outcome is a
 * function of them and the caller has no say in it. Sealing the method as well
 * would buy nothing and would put the engine out of reach of the tests that hold
 * it to the decision table.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ControlFinding
{
    use SealedValue;

    /**
     * @param list<Observation>      $evidence
     * @param list<non-empty-string> $remediations
     * @param non-empty-string|null  $probeId          null for operator-responsibility controls
     */
    private function __construct(
        public ControlDeclaration $declaration,
        public ControlOutcome $outcome,
        public string $summary,
        public array $evidence = [],
        public array $remediations = [],
        public ?string $probeId = null,
        public string $probeDescription = '',
    ) {}

    /**
     * Assess one declared control against the gathered evidence.
     *
     * The single entry point, and the reason the outcome cannot be authored: it
     * is not a parameter of anything reachable from outside this class. Which of
     * the two branches runs is decided by the declaration's own type — a
     * declaration with a probe is assessed, one without is a checklist item — so
     * a control cannot be moved off the assessed path by anything a caller
     * passes either.
     *
     * @throws IncompleteEvidenceException   when the probe requires a fact the
     *         gatherer did not produce
     * @throws InadmissibleEvidenceException when the probe's requirement cannot
     *         yield a defensible verdict; a defect in the probe, not a finding
     */
    #[NoDiscard]
    public static function assess(ControlDeclaration $declaration, ControlEvidence $evidence): self
    {
        $probe = $declaration->probe;

        if ($probe === null) {
            return new self(
                declaration: $declaration,
                outcome: ControlOutcome::OperatorResponsibility,
                summary: 'Discharged outside the software. Assessor artefact: '
                    . $declaration->operatorArtefact,
            );
        }

        // The declaration supplies the estate, the probe supplies the facts, and
        // the engine supplies the evidence. Three sources, none of which can
        // choose an outcome on its own: a probe cannot widen a control's estate to
        // fit the facts it already has, and a mapping cannot name facts.
        $verdict = ProbeVerdict::reach($probe->requirement(), $evidence, $declaration->assessedSubject());

        return new self(
            declaration: $declaration,
            outcome: $verdict->outcome,
            summary: $verdict->summary,
            evidence: $verdict->evidence,
            remediations: $verdict->remediations,
            probeId: $probe->id(),
            probeDescription: $probe->describe(),
        );
    }

    /**
     * Whether this finding should fail the report.
     *
     * `$strict` additionally fails on Partial: a residual gap is a gap when the
     * operator says it is.
     */
    #[NoDiscard]
    public function isFailing(bool $strict = false): bool
    {
        return $this->outcome->isGap()
            || ($strict && $this->outcome === ControlOutcome::Partial);
    }
}
