<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Aggregation;

use function is_int;
use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

use function usort;

/**
 * Reconstructs event timelines by correlation ID.
 *
 * Given a requestId or jobId, retrieves all events that share that
 * correlation and orders them by timestamp for timeline display.
 */
#[Internal]
final readonly class TimelineBuilder
{
    public function __construct(
        private readonly EventStoreInterface $store,
    ) {}

    /**
     * Build a timeline for a specific request.
     *
     * @return list<array<string, mixed>>
     */
    public function forRequest(string $requestId): array
    {
        $events = $this->store->query(['request_id' => $requestId], limit: 1000);

        return $this->sortByTimestamp($events);
    }

    /**
     * Build a timeline for a specific job.
     *
     * @return list<array<string, mixed>>
     */
    public function forJob(string $jobId): array
    {
        $events = $this->store->query(['job_id' => $jobId], limit: 1000);

        return $this->sortByTimestamp($events);
    }

    /**
     * Build a timeline for a specific trace.
     *
     * @return list<array<string, mixed>>
     */
    public function forTrace(string $traceId): array
    {
        $events = $this->store->query(['trace_id' => $traceId], limit: 1000);

        return $this->sortByTimestamp($events);
    }

    /**
     * Build a combined timeline for a correlation context.
     * Accepts any combination of requestId, jobId, traceId.
     *
     * @return list<array<string, mixed>>
     */
    public function forCorrelation(?string $requestId = null, ?string $jobId = null, ?string $traceId = null): array
    {
        $events = [];

        if ($requestId !== null) {
            $events = [...$events, ...$this->store->query(['request_id' => $requestId], limit: 1000)];
        }

        if ($jobId !== null) {
            $jobEvents = $this->store->query(['job_id' => $jobId], limit: 1000);
            // Merge without duplicates by event_id
            $existingIds = [];
            foreach ($events as $e) {
                /** @var string $eId */
                $eId = $e['event_id'] ?? '';
                $existingIds[$eId] = true;
            }
            foreach ($jobEvents as $je) {
                /** @var string $jeId */
                $jeId = $je['event_id'] ?? '';
                if (!isset($existingIds[$jeId])) {
                    $events[] = $je;
                }
            }
        }

        if ($traceId !== null) {
            $traceEvents = $this->store->query(['trace_id' => $traceId], limit: 1000);
            $existingIds = [];
            foreach ($events as $e) {
                /** @var string $eId */
                $eId = $e['event_id'] ?? '';
                $existingIds[$eId] = true;
            }
            foreach ($traceEvents as $te) {
                /** @var string $teId */
                $teId = $te['event_id'] ?? '';
                if (!isset($existingIds[$teId])) {
                    $events[] = $te;
                }
            }
        }

        return $this->sortByTimestamp($events);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function sortByTimestamp(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            $aTs = isset($a['timestamp_us']) && (is_int($a['timestamp_us']) || is_string($a['timestamp_us'])) ? (int) $a['timestamp_us'] : 0;
            $bTs = isset($b['timestamp_us']) && (is_int($b['timestamp_us']) || is_string($b['timestamp_us'])) ? (int) $b['timestamp_us'] : 0;

            return $aTs <=> $bTs;
        });

        return $events;
    }
}
