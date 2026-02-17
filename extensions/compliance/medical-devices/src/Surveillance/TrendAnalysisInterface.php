<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use Pulsar\Api\Api;

/**
 * Contract for trend analysis of adverse events and complaints.
 *
 * MDR Article 83(3) requires manufacturers to detect statistically
 * significant increases in the frequency or severity of incidents.
 */
#[Api(since: '1.0.0')]
interface TrendAnalysisInterface
{
    /**
     * Analyze event trends for a device over a period.
     *
     * @return TrendResult The analysis result with significance flags
     */
    public function analyze(string $deviceIdentifier, string $periodStart, string $periodEnd): TrendResult;

    /**
     * Check if the event frequency exceeds the expected baseline.
     */
    public function isSignificantIncrease(string $deviceIdentifier, int $currentPeriodEvents, int $baselineEvents): bool;
}
