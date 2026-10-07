<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\Observation;

use function implode;
use function sprintf;
use function str_replace;
use function trim;

/**
 * The document an assessor is handed, and the generator for the compliance
 * matrix page.
 *
 * The matrix is written from an assessed deployment rather than by hand, which
 * is what stops it drifting from what the software actually does — the drift
 * that let a control describe a class that could not be constructed.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class MarkdownReportRenderer implements ReportRendererInterface
{
    #[Override]
    public function render(ComplianceAssessment $assessment): string
    {
        $context = $assessment->context;
        $summary = $assessment->summary();

        $lines = [
            '# Compliance report',
            '',
            '| Field | Value |',
            '| --- | --- |',
            sprintf('| Application | %s |', $this->cell($context->applicationName)),
            sprintf('| Environment | %s |', $this->cell($context->environment)),
            sprintf('| Generated | %s |', $this->cell($context->generatedAtIso())),
            sprintf(
                '| Observed via | php %s / sapi=%s |',
                $this->cell($context->phpVersion),
                $this->cell($context->sapi),
            ),
            sprintf('| Frameworks | %s |', $this->frameworkList($assessment)),
            sprintf('| Framework source | `%s` |', $this->cell($context->frameworkSource)),
            '',
            sprintf(
                '%d control%s assessed: **%d satisfied**, %d partial, **%d gap%s**. '
                    . 'Probed coverage %.1f%% of assessed controls. A further %d scoped out on an '
                    . 'operator assertion and %d operator responsibilities are counted toward nothing.',
                $summary->assessed,
                $summary->assessed === 1 ? '' : 's',
                $summary->satisfied,
                $summary->partial,
                $summary->gaps,
                $summary->gaps === 1 ? '' : 's',
                $summary->probedCoveragePercent(),
                $summary->notApplicable,
                $summary->operatorChecklist,
            ),
            '',
        ];

        foreach ($assessment->frameworks as $framework) {
            foreach ($this->frameworkSection($assessment, $framework) as $line) {
                $lines[] = $line;
            }
        }

        foreach ($this->checklist($assessment) as $line) {
            $lines[] = $line;
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = sprintf('_%s_', ReportDisclaimer::TEXT);

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function frameworkSection(ComplianceAssessment $assessment, ComplianceFramework $framework): array
    {
        $findings = $assessment->findingsFor($framework);

        $lines = ['## ' . $framework->value, ''];

        if ($findings === []) {
            $lines[] = 'No controls are declared for this framework.';
            $lines[] = '';

            return $lines;
        }

        $lines[] = '| Control | Outcome | Probe | Finding |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($findings as $finding) {
            $lines[] = sprintf(
                '| `%s` %s | %s | %s | %s |',
                $this->cell($finding->declaration->id),
                $this->cell($finding->declaration->title),
                $this->cell($finding->outcome->value),
                $finding->probeId === null ? '-' : sprintf('`%s`', $this->cell($finding->probeId)),
                $this->cell($finding->summary),
            );
        }

        $lines[] = '';

        foreach ($findings as $finding) {
            foreach ($this->detail($finding) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function detail(ControlFinding $finding): array
    {
        if ($finding->evidence === [] && $finding->remediations === []) {
            return [];
        }

        $lines = [
            sprintf('### %s — %s', $finding->declaration->id, $finding->declaration->title),
            '',
            sprintf('> %s', $finding->declaration->requirement),
            '',
        ];

        $scope = ReportVocabulary::scopeAssertion($finding);

        if ($scope !== null) {
            $lines[] = sprintf('Not applicable, asserted by the operator: %s', $scope->detail);
            $lines[] = '';

            return $lines;
        }

        if ($finding->evidence !== []) {
            $lines[] = '| Grade | Observed | Detail | Observed by |';
            $lines[] = '| --- | --- | --- | --- |';

            foreach ($finding->evidence as $observation) {
                $lines[] = $this->evidenceRow($observation);
            }

            $lines[] = '';
        }

        foreach ($finding->remediations as $remediation) {
            $lines[] = sprintf('- Fix: %s', $remediation);
        }

        if ($finding->remediations !== []) {
            $lines[] = '';
        }

        return $lines;
    }

    private function evidenceRow(Observation $observation): string
    {
        return sprintf(
            '| %s | %s | %s | `%s` |',
            $this->cell(ReportVocabulary::evidenceLabel($observation)),
            ReportVocabulary::presence($observation),
            $this->cell($observation->detail),
            $this->cell($observation->observedBy),
        );
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

        $lines = [
            '## Operator responsibilities',
            '',
            ReportDisclaimer::CHECKLIST_NOTE,
            '',
            '| Control | Framework | Artefact to produce |',
            '| --- | --- | --- |',
        ];

        foreach ($checklist as $finding) {
            $lines[] = sprintf(
                '| `%s` %s | %s | %s |',
                $this->cell($finding->declaration->id),
                $this->cell($finding->declaration->title),
                $this->cell($finding->declaration->framework->value),
                $this->cell($finding->declaration->operatorArtefact),
            );
        }

        $lines[] = '';

        return $lines;
    }

    private function frameworkList(ComplianceAssessment $assessment): string
    {
        if ($assessment->frameworks === []) {
            return '(none)';
        }

        $names = [];

        foreach ($assessment->frameworks as $framework) {
            $names[] = sprintf('`%s`', $framework->value);
        }

        return implode(', ', $names);
    }

    /**
     * Keep a value inside its table cell: a pipe would end the column early and
     * a newline would end the row, either of which silently drops evidence from
     * the document handed to an assessor.
     */
    private function cell(string $value): string
    {
        return trim(str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value));
    }
}
