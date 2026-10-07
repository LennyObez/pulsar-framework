<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function round;

/**
 * The arithmetic of one assessment run.
 *
 * Probed coverage and the operator checklist are separate figures and must stay
 * separate wherever they are printed. Folding them into one percentage is how a
 * catalogue that is mostly checklist comes to read as mostly covered — the
 * inflation the whole design exists to prevent, achieved without a single false
 * claim being made.
 *
 * THE CONSTRUCTOR TOOK SIX INTEGERS AND WAS PUBLIC, which made the one number an
 * operator reads first — the coverage percentage — the one number in the whole
 * subsystem a caller could simply write down. `new CoverageSummary(assessed: 4,
 * satisfied: 4, ...)` is a compliance claim with no findings behind it at all.
 * The constructor is private now, and {@see over()} counts a list of findings
 * instead: every figure is derived from outcomes that were reached from gathered
 * evidence, and there is no expression anywhere that produces a summary saying
 * something the findings do not.
 *
 * {@see over()} is deliberately NOT sealed to the measuring component. It has
 * nothing to seal: a finding cannot be forged, so counting findings cannot lie,
 * and a renderer or a console command must be able to total up whatever subset of
 * a report it is showing.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class CoverageSummary
{
    use SealedValue;

    /**
     * @param int $assessed           Controls whose outcome the framework judged (the denominator)
     * @param int $satisfied          Observed working
     * @param int $partial            Partly observed, residual gap named
     * @param int $gaps               Claimed by an enabled mapping and not observed
     * @param int $notApplicable      Scoped out on a recorded operator assertion, or with no
     *                                subject in this deployment
     * @param int $operatorChecklist  Controls with no probe, each naming an assessor artefact
     */
    private function __construct(
        public int $assessed,
        public int $satisfied,
        public int $partial,
        public int $gaps,
        public int $notApplicable,
        public int $operatorChecklist,
    ) {}

    /**
     * Count a set of findings.
     *
     * The only way to obtain a summary, and every field is a tally rather than an
     * argument. Which findings are counted is the caller's choice — one framework,
     * the whole catalogue — and what the tally SAYS about them is not.
     *
     * @param list<ControlFinding> $findings
     */
    #[NoDiscard]
    public static function over(array $findings): self
    {
        $assessed = 0;
        $satisfied = 0;
        $partial = 0;
        $gaps = 0;
        $notApplicable = 0;
        $checklist = 0;

        foreach ($findings as $finding) {
            if ($finding->outcome->countsTowardCoverage()) {
                ++$assessed;
            }

            match ($finding->outcome) {
                ControlOutcome::Satisfied => ++$satisfied,
                ControlOutcome::Partial => ++$partial,
                ControlOutcome::Unsatisfied => ++$gaps,
                ControlOutcome::NotApplicable => ++$notApplicable,
                ControlOutcome::OperatorResponsibility => ++$checklist,
            };
        }

        return new self(
            assessed: $assessed,
            satisfied: $satisfied,
            partial: $partial,
            gaps: $gaps,
            notApplicable: $notApplicable,
            operatorChecklist: $checklist,
        );
    }

    /**
     * Share of ASSESSED controls observed working, to one decimal.
     *
     * Partial deliberately contributes nothing. Half-crediting it is what turns
     * "we observed part of this" into a number that reads like progress, and the
     * residual gap is already named in the finding for anyone who wants it.
     */
    #[NoDiscard]
    public function probedCoveragePercent(): float
    {
        if ($this->assessed === 0) {
            return 0.0;
        }

        return round((float) $this->satisfied / (float) $this->assessed * 100.0, 1);
    }

    #[NoDiscard]
    public function hasGaps(): bool
    {
        return $this->gaps > 0;
    }
}
