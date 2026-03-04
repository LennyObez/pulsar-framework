<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

/**
 * Tracks DSAR deadline compliance and alerts on overdue requests.
 *
 * Under GDPR Article 12(3), controllers must respond to DSARs within
 * 30 calendar days. This tracker monitors open requests and alerts
 * when deadlines are approaching or have passed.
 */
#[Api(since: '1.0.0')]
final readonly class DsarDeadlineTracker
{
    /** Warning threshold in days before deadline. */
    private const int WARNING_THRESHOLD_DAYS = 5;

    public function __construct(
        private DsarStoreInterface $store,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Check all open requests and report on deadline status.
     *
     * @return DsarDeadlineReport Summary of all open requests by deadline status
     */
    #[NoDiscard]
    public function check(): DsarDeadlineReport
    {
        $requests = $this->store->findAll();
        $overdue = [];
        $atRisk = [];
        $onTrack = [];

        foreach ($requests as $request) {
            if ($request->status === DsarStatus::Completed || $request->status === DsarStatus::Downloaded || $request->status === DsarStatus::Rejected) {
                continue;
            }

            $remaining = $request->remainingDays();

            if ($remaining < 0) {
                $overdue[] = $request;
                $this->logger?->critical('DSAR deadline exceeded', [
                    'request_id' => $request->id,
                    'subject_id' => $request->subjectId,
                    'days_overdue' => abs($remaining),
                ]);
            } elseif ($remaining <= self::WARNING_THRESHOLD_DAYS) {
                $atRisk[] = $request;
                $this->logger?->warning('DSAR deadline approaching', [
                    'request_id' => $request->id,
                    'days_remaining' => $remaining,
                ]);
            } else {
                $onTrack[] = $request;
            }
        }

        return new DsarDeadlineReport($overdue, $atRisk, $onTrack);
    }
}
