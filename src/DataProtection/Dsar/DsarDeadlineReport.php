<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Report on DSAR deadline compliance across all open requests.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DsarDeadlineReport
{
    /**
     * @param list<DsarRequest> $overdue Requests past the 30-day deadline
     * @param list<DsarRequest> $atRisk Requests within 5 days of the deadline
     * @param list<DsarRequest> $onTrack Requests with more than 5 days remaining
     */
    public function __construct(
        public array $overdue,
        public array $atRisk,
        public array $onTrack,
    ) {}

    /**
     * Whether any requests have exceeded the GDPR deadline.
     */
    #[NoDiscard]
    public function hasOverdue(): bool
    {
        return $this->overdue !== [];
    }

    /**
     * Whether all open requests are on track.
     */
    #[NoDiscard]
    public function isCompliant(): bool
    {
        return $this->overdue === [];
    }
}
