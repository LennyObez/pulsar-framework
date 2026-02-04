<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use function array_values;
use function count;

use Override;
use Throwable;

use function usort;

/**
 * Groups errors by fingerprint for aggregated error reporting.
 *
 * When the maximum number of groups is reached, the oldest group
 * (by lastSeen) is evicted to make room.
 */
final class ErrorAggregator implements ErrorAggregatorInterface
{
    /** @var array<string, ErrorGroup> fingerprint => group */
    private array $groups = [];

    /** @var list<callable(ErrorEvent): void> */
    private array $observers = [];

    public function __construct(
        private readonly int $maxGroups = 500,
        private readonly int $maxRecentEventsPerGroup = 5,
    ) {}

    /**
     * Capture an error event and group it by fingerprint.
     */
    public function capture(ErrorEvent $event): void
    {
        $key = $event->fingerprint->value;

        if (!isset($this->groups[$key])) {
            // Evict oldest group if at capacity
            if (count($this->groups) >= $this->maxGroups) {
                $this->evictOldest();
            }

            $this->groups[$key] = new ErrorGroup($event->fingerprint, $this->maxRecentEventsPerGroup);
        }

        $this->groups[$key]->record($event);

        foreach ($this->observers as $observer) {
            try {
                $observer($event);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Register an observer to be notified on every captured error event.
     *
     * @param callable(ErrorEvent): void $observer
     */
    #[Override]
    public function addObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Get all error groups sorted by lastSeen descending.
     *
     * @return list<ErrorGroup>
     */
    public function groups(): array
    {
        $groups = array_values($this->groups);

        usort(
            $groups,
            static fn(ErrorGroup $a, ErrorGroup $b): int => $b->lastSeen() <=> $a->lastSeen(),
        );

        return $groups;
    }

    /**
     * Get a specific error group by fingerprint.
     */
    public function group(ErrorFingerprint $fingerprint): ?ErrorGroup
    {
        return $this->groups[$fingerprint->value] ?? null;
    }

    /**
     * Get the total number of error groups.
     */
    public function count(): int
    {
        return count($this->groups);
    }

    /**
     * Clear all error groups.
     */
    public function clear(): void
    {
        $this->groups = [];
    }

    /**
     * Evict the group with the oldest lastSeen timestamp.
     */
    private function evictOldest(): void
    {
        $oldestKey = null;
        $oldestTime = null;

        foreach ($this->groups as $key => $group) {
            if ($oldestTime === null || $group->lastSeen() < $oldestTime) {
                $oldestKey = $key;
                $oldestTime = $group->lastSeen();
            }
        }

        if ($oldestKey !== null) {
            unset($this->groups[$oldestKey]);
        }
    }
}
