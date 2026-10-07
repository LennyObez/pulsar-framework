<?php

declare(strict_types=1);

namespace Pulsar\Security\Posture;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;

/**
 * Aggregated result of the security-posture preflight: every evaluated control
 * plus convenience views over failures and degradations.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityPostureReport
{
    /**
     * @param list<SecurityPostureItem> $items
     */
    public function __construct(public array $items) {}

    /**
     * The worst status across all items ({@see SecurityPostureStatus::Ok} when
     * there are none).
     */
    public function overallStatus(): SecurityPostureStatus
    {
        $status = SecurityPostureStatus::Ok;

        foreach ($this->items as $item) {
            $status = $status->worst($item->status);
        }

        return $status;
    }

    /**
     * @return list<SecurityPostureItem>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(SecurityPostureItem $i): bool => $i->status === SecurityPostureStatus::Fail,
        ));
    }

    /**
     * @return list<SecurityPostureItem>
     */
    public function degraded(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn(SecurityPostureItem $i): bool => $i->status === SecurityPostureStatus::Degraded,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->failures() !== [];
    }

    public function hasDegraded(): bool
    {
        return $this->degraded() !== [];
    }
}
