<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\Observation;

use function array_map;
use function explode;
use function implode;
use function sprintf;
use function str_repeat;
use function wordwrap;

/**
 * The report an operator reads at a terminal, gaps first.
 *
 * Every line an assessor might challenge carries its source: the probe that
 * concluded it, the class that observed each fact, the grade of that fact, and
 * for a not-applicable control the config key and value the operator signed.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class TextReportRenderer implements ReportRendererInterface
{
    private const int WIDTH = 76;

    /** Indent of every continuation line, aligned under the control title. */
    private const int INDENT = 9;

    #[Override]
    public function render(ComplianceAssessment $assessment): string
    {
        $lines = $this->header($assessment);

        foreach ($assessment->frameworks as $framework) {
            foreach ($this->frameworkSection($assessment, $framework) as $line) {
                $lines[] = $line;
            }
        }

        foreach ($this->checklist($assessment) as $line) {
            $lines[] = $line;
        }

        foreach ($this->footer($assessment) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function header(ComplianceAssessment $assessment): array
    {
        $context = $assessment->context;

        $frameworks = $assessment->frameworks === []
            ? '(none)'
            : implode(', ', array_map(
                static fn(ComplianceFramework $framework): string => $framework->value,
                $assessment->frameworks,
            ));

        return [
            'Pulsar compliance report',
            sprintf('  application   %s (%s)', $context->applicationName, $context->environment),
            sprintf('  generated     %s', $context->generatedAtIso()),
            sprintf('  observed via  php %s / sapi=%s', $context->phpVersion, $context->sapi),
            sprintf('  frameworks    %s', $frameworks),
            sprintf('                (from %s)', $context->frameworkSource),
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function frameworkSection(ComplianceAssessment $assessment, ComplianceFramework $framework): array
    {
        $findings = $assessment->findingsFor($framework);

        if ($findings === []) {
            return [
                sprintf('%s   no controls are declared for this framework', $framework->value),
                '',
            ];
        }

        $summary = ControlAssessment::summarize($findings);

        $lines = [
            sprintf(
                '%s   %d assessed — %d satisfied, %d partial, %d gap%s; %d not applicable, %d operator',
                $framework->value,
                $summary->assessed,
                $summary->satisfied,
                $summary->partial,
                $summary->gaps,
                $summary->gaps === 1 ? '' : 's',
                $summary->notApplicable,
                $summary->operatorChecklist,
            ),
            '',
        ];

        foreach ($findings as $finding) {
            foreach ($this->finding($finding) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function finding(ControlFinding $finding): array
    {
        $declaration = $finding->declaration;

        $lines = [sprintf(
            '  [%s] %-8s %s',
            ReportVocabulary::marker($finding->outcome),
            $declaration->id,
            $declaration->title,
        )];

        $lines[] = $this->indent(
            $finding->probeId === null
                ? $finding->summary
                : sprintf('%s — %s', $finding->probeId, $finding->summary),
        );

        $scope = ReportVocabulary::scopeAssertion($finding);

        if ($scope !== null) {
            $lines[] = $this->indent('not applicable — asserted by the operator:');
            $lines[] = $this->indent('  ' . $scope->detail);
        } elseif ($finding->evidence !== []) {
            $lines[] = $this->indent('evidence:');

            foreach ($finding->evidence as $observation) {
                foreach ($this->observation($observation) as $line) {
                    $lines[] = $line;
                }
            }
        }

        foreach ($finding->remediations as $remediation) {
            $lines[] = $this->indent(sprintf('fix: %s', $remediation));
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * One evidence line, with its grade and the class that produced it.
     *
     * The detail is printed for a negative observation too: a fact that did not
     * hold must still name what stood there instead, or the reader is left to
     * assume the check simply did not run.
     *
     * @return list<string>
     */
    private function observation(Observation $observation): array
    {
        return [
            $this->indent(sprintf(
                '  %-10s %s',
                ReportVocabulary::evidenceLabel($observation),
                $observation->detail,
            )),
            $this->indent(sprintf('  %-10s (%s)', '', $observation->observedBy)),
        ];
    }

    /**
     * @return list<string>
     */
    private function checklist(ComplianceAssessment $assessment): array
    {
        $checklist = $assessment->operatorResponsibilities();

        if ($checklist === []) {
            return [];
        }

        $lines = ['Operator responsibilities'];

        foreach (self::wrapped(ReportDisclaimer::CHECKLIST_NOTE) as $line) {
            $lines[] = '  ' . $line;
        }

        $lines[] = '';

        foreach ($checklist as $finding) {
            $lines[] = sprintf(
                '  [ ] %-8s %s (%s)',
                $finding->declaration->id,
                $finding->declaration->title,
                $finding->declaration->framework->value,
            );
            $lines[] = $this->indent(sprintf('artefact: %s', $finding->declaration->operatorArtefact));
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function footer(ComplianceAssessment $assessment): array
    {
        $summary = $assessment->summary();

        $lines = [
            str_repeat('-', self::WIDTH),
            sprintf(
                'assessed %d control%s: %d satisfied, %d partial, %d gap%s',
                $summary->assessed,
                $summary->assessed === 1 ? '' : 's',
                $summary->satisfied,
                $summary->partial,
                $summary->gaps,
                $summary->gaps === 1 ? '' : 's',
            ),
            sprintf('probed coverage %.1f%% of assessed controls', $summary->probedCoveragePercent()),
            sprintf(
                'not applicable %d, operator checklist %d — neither counted as coverage',
                $summary->notApplicable,
                $summary->operatorChecklist,
            ),
            '',
        ];

        foreach (self::wrapped(ReportDisclaimer::TEXT) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function wrapped(string $text): array
    {
        return explode("\n", wordwrap($text, self::WIDTH));
    }

    private function indent(string $text): string
    {
        return str_repeat(' ', self::INDENT) . $text;
    }
}
