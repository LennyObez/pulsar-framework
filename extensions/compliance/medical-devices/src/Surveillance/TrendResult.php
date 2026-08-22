<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Surveillance;

use Pulsar\Api\Api;

/**
 * Result of a trend analysis.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TrendResult
{
    public function __construct(
        public string $deviceIdentifier,
        public string $periodStart,
        public string $periodEnd,
        public int $totalEvents,
        public bool $significantIncrease,
        public ?float $changePercentage = null,
        public ?string $summary = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'device_identifier' => $this->deviceIdentifier,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'total_events' => $this->totalEvents,
            'significant_increase' => $this->significantIncrease,
        ];

        if ($this->changePercentage !== null) {
            $data['change_percentage'] = $this->changePercentage;
        }

        if ($this->summary !== null) {
            $data['summary'] = $this->summary;
        }

        return $data;
    }
}
