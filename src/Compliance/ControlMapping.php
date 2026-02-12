<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Maps framework features to regulatory controls and produces coverage reports.
 *
 * This is a bidirectional mapping: given a feature name, you can find which
 * controls it provides coverage for; given a control ID, you can find which
 * features address it.
 */
#[Api(since: '1.0.0')]
final class ControlMapping
{
    /** @var array<string, list<string>> Feature name => list of control IDs */
    private array $featureToControls = [];

    /** @var array<string, list<string>> Control ID => list of feature names */
    private array $controlToFeatures = [];

    public function __construct(
        private readonly ControlCatalog $catalog,
    ) {}

    /**
     * Map a framework feature to a regulatory control.
     *
     * Both the feature-to-control and control-to-feature indices are updated.
     * Duplicate mappings are silently ignored.
     */
    public function map(string $featureName, string $controlId): void
    {
        if (! isset($this->featureToControls[$featureName])) {
            $this->featureToControls[$featureName] = [];
        }

        if (! in_array($controlId, $this->featureToControls[$featureName], true)) {
            $this->featureToControls[$featureName][] = $controlId;
        }

        if (! isset($this->controlToFeatures[$controlId])) {
            $this->controlToFeatures[$controlId] = [];
        }

        if (! in_array($featureName, $this->controlToFeatures[$controlId], true)) {
            $this->controlToFeatures[$controlId][] = $featureName;
        }
    }

    /**
     * Return all controls that a given feature provides coverage for.
     *
     * @return list<Control>
     */
    #[NoDiscard]
    public function controlsForFeature(string $featureName): array
    {
        $controlIds = $this->featureToControls[$featureName] ?? [];
        $controls = [];

        foreach ($controlIds as $controlId) {
            $control = $this->catalog->get($controlId);

            if ($control !== null) {
                $controls[] = $control;
            }
        }

        return $controls;
    }

    /**
     * Return all feature names that provide coverage for a given control.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function featuresForControl(string $controlId): array
    {
        return $this->controlToFeatures[$controlId] ?? [];
    }

    /**
     * Generate a per-framework coverage summary.
     *
     * Each framework entry includes the total number of controls, counts by
     * status, and a coverage percentage (implemented + partial weighted at 50%).
     *
     * @return array<string, array{total: int, implemented: int, partial: int, planned: int, coverage_percent: float}>
     */
    #[NoDiscard]
    public function coverageReport(): array
    {
        $report = [];

        foreach ($this->catalog->all() as $control) {
            $framework = $control->framework;

            if (! isset($report[$framework])) {
                $report[$framework] = [
                    'total' => 0,
                    'implemented' => 0,
                    'partial' => 0,
                    'planned' => 0,
                    'coverage_percent' => 0.0,
                ];
            }

            ++$report[$framework]['total'];

            match ($control->status) {
                ControlStatus::Implemented => ++$report[$framework]['implemented'],
                ControlStatus::Partial => ++$report[$framework]['partial'],
                ControlStatus::Planned => ++$report[$framework]['planned'],
                ControlStatus::NotApplicable => null,
            };
        }

        foreach ($report as $framework => $data) {
            $effectiveTotal = $data['total'] - $this->countNotApplicable($framework);

            $report[$framework]['coverage_percent'] = $effectiveTotal > 0
                ? round(((float) $data['implemented'] + (float) $data['partial'] * 0.5) / (float) $effectiveTotal * 100.0, 2)
                : 0.0;
        }

        return $report;
    }

    /**
     * Return a map of feature names to the controls they cover.
     *
     * @return array<string, list<Control>>
     */
    #[NoDiscard]
    public function featureCoverageMap(): array
    {
        $map = [];

        foreach ($this->featureToControls as $feature => $controlIds) {
            $map[$feature] = [];

            foreach ($controlIds as $controlId) {
                $control = $this->catalog->get($controlId);

                if ($control !== null) {
                    $map[$feature][] = $control;
                }
            }
        }

        return $map;
    }

    /**
     * Count controls with NotApplicable status for a given framework.
     */
    private function countNotApplicable(string $framework): int
    {
        $count = 0;

        foreach ($this->catalog->byFramework($framework) as $control) {
            if ($control->status === ControlStatus::NotApplicable) {
                ++$count;
            }
        }

        return $count;
    }
}
