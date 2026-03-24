<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use Pulsar\Api\Api;

/**
 * Result of verifying an event chain's evidence hashes.
 *
 * @psalm-api Public DTO returned from event chain verification routines;
 *            consumed by audit / governance reports.
 */
#[Api(since: '1.0.0')]
final readonly class ContentEventChainResult
{
    /**
     * @param list<ContentEvent> $events All events in the chain
     * @param list<int> $brokenLinks Sequence numbers where hash verification failed
     */
    public function __construct(
        public bool $valid,
        public array $events,
        public array $brokenLinks,
        public int $totalEvents,
    ) {}
}
