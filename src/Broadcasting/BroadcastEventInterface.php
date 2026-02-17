<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Pulsar\Api\Api;

/**
 * Contract for events that should be broadcast over WebSocket channels.
 *
 * Events implementing this interface are dispatched by the BroadcastManager
 * to all subscribers of the returned channels.
 */
#[Api(since: '1.0.0')]
interface BroadcastEventInterface
{
    /**
     * Get the channels this event should be broadcast on.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array;

    /**
     * Get the broadcast event name (used as the "event" field in the payload).
     */
    public function broadcastAs(): string;

    /**
     * Get the event payload data.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array;
}
