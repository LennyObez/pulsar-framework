<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Report;

use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\Observation;

use function array_map;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The report a pipeline diffs between runs.
 *
 * Carries the same findings as the printed document and no others, including
 * the evidence each verdict rests on: a machine that could read only a verdict
 * would be unable to tell a control that was measured from one that was
 * asserted, and that distinction is the entire point of the exercise.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class JsonReportRenderer implements ReportRendererInterface
{
    /**
     * Bumped when the document shape changes, so a consumer that pinned an
     * older shape fails loudly instead of silently reading a moved field.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * @throws JsonException when a finding carries a string the encoder rejects
     */
    #[Override]
    public function render(ComplianceAssessment $assessment): string
    {
        return json_encode(
            $this->toArray($assessment),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array{
     *     report: string,
     *     schema_version: int,
     *     generated_at: string,
     *     application: array{name: string, environment: string},
     *     runtime: array{php: string, sapi: string},
     *     frameworks: list<string>,
     *     framework_source: string,
     *     summary: array{assessed: int, satisfied: int, partial: int, gaps: int, not_applicable: int, operator_checklist: int, probed_coverage_percent: float},
     *     controls: list<array{
     *         id: string, framework: string, title: string, requirement: string,
     *         outcome: string, summary: string,
     *         probe: array{id: string, describes: string}|null,
     *         operator_artefact: string|null,
     *         evidence: list<array{id: string, grade: string, present: bool, subject_exists: bool, detail: string, observed_by: string}>,
     *         remediations: list<string>,
     *     }>,
     *     disclaimer: string,
     * }
     */
    public function toArray(ComplianceAssessment $assessment): array
    {
        $context = $assessment->context;
        $summary = $assessment->summary();

        return [
            'report' => 'pulsar.compliance',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $context->generatedAtIso(),
            'application' => [
                'name' => $context->applicationName,
                'environment' => $context->environment,
            ],
            'runtime' => [
                'php' => $context->phpVersion,
                'sapi' => $context->sapi,
            ],
            'frameworks' => array_map(
                static fn(ComplianceFramework $framework): string => $framework->value,
                $assessment->frameworks,
            ),
            'framework_source' => $context->frameworkSource,
            'summary' => [
                'assessed' => $summary->assessed,
                'satisfied' => $summary->satisfied,
                'partial' => $summary->partial,
                'gaps' => $summary->gaps,
                'not_applicable' => $summary->notApplicable,
                'operator_checklist' => $summary->operatorChecklist,
                'probed_coverage_percent' => $summary->probedCoveragePercent(),
            ],
            'controls' => array_map(
                fn(ControlFinding $finding): array => $this->control($finding),
                $assessment->findings,
            ),
            'disclaimer' => ReportDisclaimer::TEXT,
        ];
    }

    /**
     * @return array{
     *     id: string, framework: string, title: string, requirement: string,
     *     outcome: string, summary: string,
     *     probe: array{id: string, describes: string}|null,
     *     operator_artefact: string|null,
     *     evidence: list<array{id: string, grade: string, present: bool, subject_exists: bool, detail: string, observed_by: string}>,
     *     remediations: list<string>,
     * }
     */
    private function control(ControlFinding $finding): array
    {
        $declaration = $finding->declaration;

        return [
            'id' => $declaration->id,
            'framework' => $declaration->framework->value,
            'title' => $declaration->title,
            'requirement' => $declaration->requirement,
            'outcome' => $finding->outcome->value,
            'summary' => $finding->summary,
            'probe' => $finding->probeId === null
                ? null
                : ['id' => $finding->probeId, 'describes' => $finding->probeDescription],
            'operator_artefact' => $declaration->isProbed() ? null : $declaration->operatorArtefact,
            'evidence' => array_map(
                static fn(Observation $observation): array => [
                    'id' => $observation->id->value,
                    'grade' => $observation->grade->value,
                    'present' => $observation->present,
                    // Distinct from 'present': false. A machine consumer that
                    // collapsed the two would recreate the inversion this field
                    // exists to record — a deployment with nothing to encrypt
                    // reading as one that failed to.
                    'subject_exists' => $observation->subjectExists,
                    'detail' => $observation->detail,
                    'observed_by' => $observation->observedBy,
                ],
                $finding->evidence,
            ),
            'remediations' => $finding->remediations,
        ];
    }
}
