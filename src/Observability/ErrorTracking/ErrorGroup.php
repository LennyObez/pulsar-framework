<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use function array_slice;
use function count;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Aggregate of errors sharing the same fingerprint.
 *
 * Tracks occurrence count, first/last seen timestamps, and a capped
 * list of recent events.
 */
final class ErrorGroup
{
    private int $occurrenceCount = 0;
    private DateTimeImmutable $firstSeen;
    private DateTimeImmutable $lastSeen;

    /** @var list<ErrorEvent> */
    private array $recentEvents = [];

    public function __construct(
        public readonly ErrorFingerprint $fingerprint,
        private readonly int $maxRecentEvents = 5,
    ) {
        $this->firstSeen = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->lastSeen = $this->firstSeen;
    }

    /**
     * Record a new error event in this group.
     */
    public function record(ErrorEvent $event): void
    {
        ++$this->occurrenceCount;
        $this->lastSeen = $event->occurredAt;

        $this->recentEvents[] = $event;

        // Cap recent events
        if (count($this->recentEvents) > $this->maxRecentEvents) {
            $this->recentEvents = array_slice($this->recentEvents, -$this->maxRecentEvents);
        }
    }

    public function occurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function firstSeen(): DateTimeImmutable
    {
        return $this->firstSeen;
    }

    public function lastSeen(): DateTimeImmutable
    {
        return $this->lastSeen;
    }

    /**
     * @return list<ErrorEvent>
     */
    public function recentEvents(): array
    {
        return $this->recentEvents;
    }

    /**
     * Get the exception class from the most recent event, if available.
     */
    public function exceptionClass(): ?string
    {
        if ($this->recentEvents === []) {
            return null;
        }

        return $this->recentEvents[count($this->recentEvents) - 1]->exceptionClass;
    }

    /**
     * Get the message from the most recent event, if available.
     */
    public function message(): ?string
    {
        if ($this->recentEvents === []) {
            return null;
        }

        return $this->recentEvents[count($this->recentEvents) - 1]->message;
    }
}
