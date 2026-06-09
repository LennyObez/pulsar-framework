<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Moderation thresholds configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ModerationConfig
{
    /**
     * @param int $autoHideThreshold Number of reports before auto-hiding content
     * @param int $notifyThreshold Number of reports before notifying moderators
     * @param int $dismissedReportRetentionDays Days to retain dismissed reports
     */
    public function __construct(
        public int $autoHideThreshold = 5,
        public int $notifyThreshold = 3,
        public int $dismissedReportRetentionDays = 90,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            autoHideThreshold: Coerce::int($data['auto_hide_threshold'] ?? null, 5),
            notifyThreshold: Coerce::int($data['notify_threshold'] ?? null, 3),
            dismissedReportRetentionDays: Coerce::int($data['dismissed_report_retention_days'] ?? null, 90),
        );
    }
}
