<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Transparency;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Annual transparency report data structure per DSA Article 15.
 *
 * All intermediary services must publish at least one transparency
 * report per year. Online platforms and VLOPs have additional
 * reporting obligations including automated detection statistics,
 * trusted flagger data, and out-of-court dispute outcomes.
 */
#[Api(since: '1.0.0')]
final readonly class TransparencyReport
{
    /**
     * @param string                 $reportId               Unique report identifier
     * @param string                 $platformName           Name of the reporting platform
     * @param string                 $platformType           Platform type: intermediary, hosting, platform, vlop
     * @param DateTimeImmutable      $periodStart            Reporting period start date
     * @param DateTimeImmutable      $periodEnd              Reporting period end date
     * @param DateTimeImmutable      $generatedAt            When the report was generated
     * @param int                    $totalModerationActions  Total number of moderation actions taken
     * @param array<string, int>     $actionsByType          Breakdown by action type (remove, restrict, etc.)
     * @param array<string, int>     $actionsByDetection     Breakdown by detection method (automated, human, etc.)
     * @param int                    $appealsReceived        Number of appeals received (Art. 20)
     * @param int                    $appealsUpheld          Number of appeals where original decision was upheld
     * @param int                    $appealsOverturned      Number of appeals where original decision was overturned
     * @param int                    $ordersFromAuthorities  Number of orders received from authorities (Art. 9-10)
     * @param int                    $trustedFlaggerNotices  Number of notices from trusted flaggers (Art. 22)
     * @param float                  $medianProcessingHours  Median time to process notices
     * @param string                 $contactPoint           Contact point per Art. 11
     */
    public function __construct(
        public string $reportId,
        public string $platformName,
        public string $platformType,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $generatedAt,
        public int $totalModerationActions,
        public array $actionsByType,
        public array $actionsByDetection,
        public int $appealsReceived = 0,
        public int $appealsUpheld = 0,
        public int $appealsOverturned = 0,
        public int $ordersFromAuthorities = 0,
        public int $trustedFlaggerNotices = 0,
        public float $medianProcessingHours = 0.0,
        public string $contactPoint = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'report_id' => $this->reportId,
            'platform_name' => $this->platformName,
            'platform_type' => $this->platformType,
            'period_start' => $this->periodStart->format('Y-m-d'),
            'period_end' => $this->periodEnd->format('Y-m-d'),
            'generated_at' => $this->generatedAt->format('Y-m-d\TH:i:sP'),
            'total_moderation_actions' => $this->totalModerationActions,
            'actions_by_type' => $this->actionsByType,
            'actions_by_detection' => $this->actionsByDetection,
            'appeals_received' => $this->appealsReceived,
            'appeals_upheld' => $this->appealsUpheld,
            'appeals_overturned' => $this->appealsOverturned,
            'orders_from_authorities' => $this->ordersFromAuthorities,
            'trusted_flagger_notices' => $this->trustedFlaggerNotices,
            'median_processing_hours' => $this->medianProcessingHours,
            'contact_point' => $this->contactPoint,
        ];
    }
}
