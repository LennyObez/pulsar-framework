<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A visitor session with 30-minute inactivity window.
 */
#[Api(since: '1.0.0')]
final readonly class Session
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $visitorId,
        public string $sessionId,
        public string $entryPage,
        public string $exitPage,
        public int $pageCount = 1,
        public int $durationSeconds = 0,
        public bool $isBounce = true,
        public DateTimeImmutable $startedAt = new DateTimeImmutable(),
        public DateTimeImmutable $endedAt = new DateTimeImmutable(),
    ) {}

    /**
     * Create a new session with updated exit page and counters.
     */
    public function withPageView(string $exitPage, DateTimeImmutable $now): self
    {
        $duration = $now->getTimestamp() - $this->startedAt->getTimestamp();

        return clone($this, [
            'exitPage' => $exitPage,
            'pageCount' => $this->pageCount + 1,
            'durationSeconds' => max(0, $duration),
            'isBounce' => false,
            'endedAt' => $now,
        ]);
    }
}
