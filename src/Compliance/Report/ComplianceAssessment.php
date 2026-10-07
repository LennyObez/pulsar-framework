<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\CoverageSummary;

use function array_filter;
use function array_values;

/**
 * One assessment run: what was assessed, where, and what came of it.
 *
 * Every renderer takes this object and nothing else, so the terminal output,
 * the assessor's document and the pipeline's JSON are three spellings of one set
 * of findings rather than three chances to disagree. It carries no collaborator
 * that could be asked a fresh question either: by the time a renderer sees it,
 * the deployment has been observed once and the answers are fixed.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ComplianceAssessment
{
    /**
     * @param list<ComplianceFramework> $frameworks Frameworks the deployment declared it must satisfy
     * @param list<ControlFinding>      $findings   Every assessed control, worst-first within framework
     */
    public function __construct(
        public ReportContext $context,
        public array $frameworks,
        public array $findings,
    ) {}

    #[NoDiscard]
    public function summary(): CoverageSummary
    {
        return ControlAssessment::summarize($this->findings);
    }

    /**
     * The findings of one framework, in the order the assessment produced them.
     *
     * @return list<ControlFinding>
     */
    #[NoDiscard]
    public function findingsFor(ComplianceFramework $framework): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(ControlFinding $finding): bool => $finding->declaration->framework === $framework,
        ));
    }

    /**
     * Controls an enabled framework claims and the deployment does not show.
     *
     * @return list<ControlFinding>
     */
    #[NoDiscard]
    public function gaps(): array
    {
        return $this->withOutcome(ControlOutcome::Unsatisfied);
    }

    /**
     * @return list<ControlFinding>
     */
    #[NoDiscard]
    public function partials(): array
    {
        return $this->withOutcome(ControlOutcome::Partial);
    }

    /**
     * The assessor's checklist: controls Pulsar cannot observe and does not count.
     *
     * @return list<ControlFinding>
     */
    #[NoDiscard]
    public function operatorResponsibilities(): array
    {
        return $this->withOutcome(ControlOutcome::OperatorResponsibility);
    }

    /**
     * Findings that must fail the report, given the operator's strictness.
     *
     * @return list<ControlFinding>
     */
    #[NoDiscard]
    public function failing(bool $strict): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(ControlFinding $finding): bool => $finding->isFailing($strict),
        ));
    }

    /**
     * @return list<ControlFinding>
     */
    private function withOutcome(ControlOutcome $outcome): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(ControlFinding $finding): bool => $finding->outcome === $outcome,
        ));
    }
}
