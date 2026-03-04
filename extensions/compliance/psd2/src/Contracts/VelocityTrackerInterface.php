<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Domain\VelocityWindow;

/**
 * Tracks transaction velocity for fraud detection.
 *
 * Records transaction counts and amounts per identity within
 * configurable time windows, supporting the velocity checks
 * required by PSD2 RTS Art. 18.
 */
#[Api(since: '1.0.0')]
interface VelocityTrackerInterface
{
    /**
     * Record a transaction for velocity tracking.
     *
     * @param string $identityId The identity making the transaction
     * @param int $amountMinorUnits Transaction amount in minor units
     * @param string $currency ISO 4217 currency code
     */
    public function record(string $identityId, int $amountMinorUnits, string $currency): void;

    /**
     * Get the current velocity window for an identity.
     *
     * @param string $identityId The identity to query
     * @param int $windowSeconds Time window in seconds
     * @param string $currency ISO 4217 currency code
     */
    public function getWindow(string $identityId, int $windowSeconds, string $currency): VelocityWindow;
}
