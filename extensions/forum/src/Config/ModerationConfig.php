<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Api;

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
     * @param array{
     *     auto_hide_threshold?: int,
     *     notify_threshold?: int,
     *     dismissed_report_retention_days?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            autoHideThreshold: $data['auto_hide_threshold'] ?? 5,
            notifyThreshold: $data['notify_threshold'] ?? 3,
            dismissedReportRetentionDays: $data['dismissed_report_retention_days'] ?? 90,
        );
    }
}
