<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function array_filter;
use function count;

/**
 * Generates compliance status reports from catalog, mapping, and verification data.
 *
 * Reports use "control coverage" language: the framework provides coverage
 * for regulatory controls, it does not guarantee compliance. Compliance is
 * an organizational responsibility that extends beyond technical controls.
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceReport
{
    public function __construct(
        private ControlCatalog $catalog,
        private ControlMapping $mapping,
        private ControlVerifier $verifier,
    ) {}

    /**
     * Generate a full compliance coverage report for a framework.
     *
     * @return array{
     *     framework: string,
     *     generated_at: string,
     *     disclaimer: string,
     *     summary: array{total: int, implemented: int, partial: int, planned: int, not_applicable: int, coverage_percent: float},
     *     verification: array{total: int, passed: int, failed: int, pass_rate: float},
     *     controls: list<array{id: string, title: string, status: string, features: list<string>, verified: bool, verification_message: string}>,
     * }
     */
    public function generate(string $framework): array
    {
        $controls = $this->catalog->byFramework($framework);
        $verificationResults = $this->verifier->verifyFramework($framework);

        $verificationMap = [];

        foreach ($verificationResults as $result) {
            $verificationMap[$result->controlId] = $result;
        }

        $controlDetails = [];

        foreach ($controls as $control) {
            $features = $this->mapping->featuresForControl($control->id);
            $verification = $verificationMap[$control->id] ?? null;

            $controlDetails[] = [
                'id' => $control->id,
                'title' => $control->title,
                'status' => $control->status->value,
                'features' => $features,
                'verified' => $verification !== null ? $verification->passed : false,
                'verification_message' => $verification !== null ? $verification->message : 'Not verified',
            ];
        }

        $total = count($controls);
        $implemented = count(array_filter($controls, static fn(Control $c): bool => $c->status === ControlStatus::Implemented));
        $partial = count(array_filter($controls, static fn(Control $c): bool => $c->status === ControlStatus::Partial));
        $planned = count(array_filter($controls, static fn(Control $c): bool => $c->status === ControlStatus::Planned));
        $notApplicable = count(array_filter($controls, static fn(Control $c): bool => $c->status === ControlStatus::NotApplicable));

        $coverable = $total - $notApplicable;
        $coveragePercent = $coverable > 0
            ? ((float) $implemented + ((float) $partial * 0.5)) / (float) $coverable * 100.0
            : 0.0;

        return [
            'framework' => $framework,
            'generated_at' => new DateTimeImmutable()->format('Y-m-d\TH:i:sP'),
            'disclaimer' => 'This report documents framework control coverage. '
                . 'It does not constitute a compliance certification. '
                . 'Compliance is an organizational responsibility that extends beyond technical controls.',
            'summary' => [
                'total' => $total,
                'implemented' => $implemented,
                'partial' => $partial,
                'planned' => $planned,
                'not_applicable' => $notApplicable,
                'coverage_percent' => round($coveragePercent, 1),
            ],
            'verification' => ControlVerifier::summarize($verificationResults),
            'controls' => $controlDetails,
        ];
    }

    /**
     * Generate a cross-framework summary.
     *
     * @param list<string> $frameworks
     *
     * @return array<string, array{total: int, implemented: int, partial: int, planned: int, not_applicable: int, coverage_percent: float}>
     */
    public function crossFrameworkSummary(array $frameworks): array
    {
        $summary = [];

        foreach ($frameworks as $framework) {
            $report = $this->generate($framework);
            $summary[$framework] = $report['summary'];
        }

        return $summary;
    }
}
