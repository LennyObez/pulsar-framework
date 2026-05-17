<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Risk;

use Pulsar\Api\Api;

/**
 * Contract for hazard analysis per ISO 14971.
 * @api
 */
#[Api(since: '1.0.0')]
interface HazardAnalysisInterface
{
    /**
     * Evaluate the risk level based on severity and probability.
     */
    public function evaluateRisk(RiskSeverity $severity, RiskProbability $probability): RiskLevel;

    /**
     * Determine if the residual risk is acceptable after controls.
     */
    public function isResidualRiskAcceptable(HazardEntry $hazard): bool;
}
