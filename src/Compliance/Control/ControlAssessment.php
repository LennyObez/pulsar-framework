<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ControlCatalog;

use function in_array;
use function strcmp;
use function usort;

/**
 * Runs every declared control's probe against one gathered evidence set.
 *
 * Replaces the deleted ControlVerifier / ComplianceReport / ComplianceStatusProvider
 * trio. Those three could report a control "verified" from a callback nobody had
 * registered, or "implemented" from a literal in a mapping file, and the two
 * answers were reported side by side as if they were about the same thing. Here
 * there is one answer per control and it always has a probe behind it.
 *
 * The evidence set is passed in, not gathered here: gathering touches a database,
 * executes health checks and recomputes an HMAC per stored audit record, so it
 * happens exactly once per run, in the composition root, and every probe reads
 * the same frozen facts. Two probes reaching different conclusions from
 * differently-timed measurements would be indefensible in an assessment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ControlAssessment
{
    public function __construct(
        private ControlCatalog $catalog,
    ) {}

    /**
     * Assess every declared control.
     *
     * @return list<ControlFinding> Worst-first within each framework
     *
     * @throws InadmissibleEvidenceException when a probe returns a verdict its
     *         evidence cannot support. Deliberately not caught: that is a bug in
     *         the probe, and reporting it as a compliance result would put a
     *         defect and a finding in the same column.
     */
    #[NoDiscard]
    public function assessAll(ControlEvidence $evidence): array
    {
        return self::ordered($this->findings($this->catalog->all(), $evidence));
    }

    /**
     * Assess the controls of the given frameworks only.
     *
     * @param list<ComplianceFramework> $frameworks
     *
     * @return list<ControlFinding> Worst-first within each framework
     *
     * @throws InadmissibleEvidenceException see {@see assessAll()}
     */
    #[NoDiscard]
    public function assessFrameworks(array $frameworks, ControlEvidence $evidence): array
    {
        $declarations = [];

        foreach ($this->catalog->all() as $declaration) {
            if (in_array($declaration->framework, $frameworks, true)) {
                $declarations[] = $declaration;
            }
        }

        return self::ordered($this->findings($declarations, $evidence));
    }

    /**
     * The arithmetic of a set of findings.
     *
     * Delegates, and the delegation is the point: the counting lives on
     * {@see CoverageSummary} beside the private constructor it is the only caller
     * of, so no second place can produce a summary that disagrees with the
     * findings it claims to describe.
     *
     * @param list<ControlFinding> $findings
     */
    #[NoDiscard]
    public static function summarize(array $findings): CoverageSummary
    {
        return CoverageSummary::over($findings);
    }

    /**
     * @param list<ControlDeclaration> $declarations
     *
     * @return list<ControlFinding>
     */
    private function findings(array $declarations, ControlEvidence $evidence): array
    {
        $findings = [];

        foreach ($declarations as $declaration) {
            $findings[] = ControlFinding::assess($declaration, $evidence);
        }

        return $findings;
    }

    /**
     * Group by framework, then order gaps before partials before satisfied, so
     * the operator reads the actionable part without scrolling.
     *
     * @param list<ControlFinding> $findings
     *
     * @return list<ControlFinding>
     */
    private static function ordered(array $findings): array
    {
        usort($findings, static function (ControlFinding $a, ControlFinding $b): int {
            $byFramework = strcmp($a->declaration->framework->value, $b->declaration->framework->value);

            if ($byFramework !== 0) {
                return $byFramework;
            }

            $bySeverity = $a->outcome->severityRank() <=> $b->outcome->severityRank();

            return $bySeverity !== 0
                ? $bySeverity
                : strcmp($a->declaration->id, $b->declaration->id);
        });

        return $findings;
    }
}
