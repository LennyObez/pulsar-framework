<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

use function in_array;

/**
 * Provides compliance status data for admin dashboards and API consumers.
 *
 * Aggregates data from the catalog, mapping, verifier, and evidence
 * systems into a unified status view.
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceStatusProvider
{
    public function __construct(
        private ControlCatalog $catalog,
        private ControlMapping $mapping,
        private ControlVerifier $verifier,
        private ComplianceReport $report,
    ) {}

    /**
     * Get the control verifier instance for direct verification checks.
     */
    public function verifier(): ControlVerifier
    {
        return $this->verifier;
    }

    /**
     * Get a high-level overview of compliance coverage.
     *
     * @return array{
     *     frameworks: list<string>,
     *     total_controls: int,
     *     coverage: array<string, array{total: int, implemented: int, partial: int, planned: int, not_applicable: int, coverage_percent: float}>,
     *     disclaimer: string,
     * }
     */
    public function overview(): array
    {
        $frameworks = [];

        foreach ($this->catalog->all() as $control) {
            if (!in_array($control->framework, $frameworks, true)) {
                $frameworks[] = $control->framework;
            }
        }

        return [
            'frameworks' => $frameworks,
            'total_controls' => $this->catalog->count(),
            'coverage' => $this->report->crossFrameworkSummary($frameworks),
            'disclaimer' => 'Control coverage assessment — does not constitute compliance certification.',
        ];
    }

    /**
     * Get detailed status for a specific framework.
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
    public function frameworkDetail(string $framework): array
    {
        return $this->report->generate($framework);
    }

    /**
     * Get the list of features that provide control coverage.
     *
     * @return array<string, list<Control>>
     */
    public function featureCoverageMap(): array
    {
        return $this->mapping->featureCoverageMap();
    }
}
