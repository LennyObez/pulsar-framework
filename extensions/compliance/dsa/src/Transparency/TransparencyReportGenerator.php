<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Transparency;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Dsa\Config\DsaConfig;
use Pulsar\Extension\Dsa\ContentModeration\ModerationLog;

use function array_sum;
use function count;
use function sort;

/**
 * Generates transparency reports from moderation logs per DSA Article 15.
 *
 * Aggregates moderation data from the ModerationLog to produce the
 * structured report mandated by the DSA. The report includes breakdowns
 * by action type, detection method, and appeal outcomes.
 */
#[Api(since: '1.0.0')]
final readonly class TransparencyReportGenerator
{
    public function __construct(
        private ModerationLog $moderationLog,
        private DsaConfig $config,
    ) {}

    /**
     * Generate a transparency report for the given period.
     */
    #[NoDiscard]
    public function generate(
        string $reportId,
        string $platformName,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $generatedAt,
        int $appealsReceived = 0,
        int $appealsUpheld = 0,
        int $appealsOverturned = 0,
        int $ordersFromAuthorities = 0,
    ): TransparencyReport {
        $actionsByType = $this->moderationLog->countByDecisionType($periodStart, $periodEnd);
        $actionsByDetection = $this->moderationLog->countByDetectionMethod($periodStart, $periodEnd);
        $decisions = $this->moderationLog->findByDateRange($periodStart, $periodEnd);

        $totalActions = array_sum($actionsByType);
        $trustedFlaggerCount = $actionsByDetection['trusted_flagger'] ?? 0;
        $medianHours = $this->calculateMedianProcessingHours($decisions);

        return new TransparencyReport(
            reportId: $reportId,
            platformName: $platformName,
            platformType: $this->config->platformType,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: $generatedAt,
            totalModerationActions: (int) $totalActions,
            actionsByType: $actionsByType,
            actionsByDetection: $actionsByDetection,
            appealsReceived: $appealsReceived,
            appealsUpheld: $appealsUpheld,
            appealsOverturned: $appealsOverturned,
            ordersFromAuthorities: $ordersFromAuthorities,
            trustedFlaggerNotices: $trustedFlaggerCount,
            medianProcessingHours: $medianHours,
            contactPoint: $this->config->contactPoint,
        );
    }

    /**
     * Calculate the median processing time in hours from decision timestamps.
     *
     * @param list<\Pulsar\Extension\Dsa\ContentModeration\ModerationDecision> $decisions
     */
    private function calculateMedianProcessingHours(array $decisions): float
    {
        if ($decisions === []) {
            return 0.0;
        }

        $timestamps = [];

        foreach ($decisions as $decision) {
            $timestamps[] = $decision->decidedAt->getTimestamp();
        }

        sort($timestamps);

        $count = count($timestamps);

        if ($count === 1) {
            return 0.0;
        }

        $intervals = [];

        for ($i = 1; $i < $count; $i++) {
            $intervals[] = ($timestamps[$i] - $timestamps[$i - 1]) / 3600.0;
        }

        sort($intervals);

        $mid = (int) (count($intervals) / 2);

        if (count($intervals) % 2 === 0) {
            return ($intervals[$mid - 1] + $intervals[$mid]) / 2.0;
        }

        return $intervals[$mid];
    }
}
