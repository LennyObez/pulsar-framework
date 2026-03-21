<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use Pulsar\Api\Api;

/**
 * Velocity tracking window for transaction monitoring.
 *
 * Records transaction count and total amount within a time window
 * for a specific identity, used for fraud scoring per PSD2 RTS Art. 18.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VelocityWindow
{
    public function __construct(
        public string $identityId,
        public int $windowSeconds,
        public int $transactionCount,
        public int $totalAmountMinorUnits,
        public string $currency,
    ) {}

    /**
     * Check if the count threshold has been exceeded.
     */
    public function exceedsCountThreshold(int $maxCount): bool
    {
        return $this->transactionCount >= $maxCount;
    }

    /**
     * Check if the amount threshold has been exceeded.
     */
    public function exceedsAmountThreshold(int $maxAmountMinorUnits): bool
    {
        return $this->totalAmountMinorUnits >= $maxAmountMinorUnits;
    }
}
