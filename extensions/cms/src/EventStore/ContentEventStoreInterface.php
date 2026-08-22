<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\EventStore;

use Pulsar\Api\Api;

/**
 * Persistence interface for the append-only content event log.
 *
 * Supports event replay for projection rebuilds and auditing.
 *
 * @psalm-api Public binding contract; implemented by DbContentEventRepository
 *            and consumed by content services in event-sourcing mode.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentEventStoreInterface
{
    /**
     * Append a new event to the event store.
     */
    public function append(ContentEvent $event): void;

    /**
     * Retrieve events for a content item, optionally starting after a given sequence.
     *
     * @return list<ContentEvent>
     */
    public function getEvents(string $contentId, ?int $afterSequence = null): array;

    /**
     * Rebuild the read model projection by replaying all events for a content item.
     */
    public function rebuildProjection(string $contentId): void;
}
