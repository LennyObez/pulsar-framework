<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an event class for automatic broadcasting.
 *
 * When the event dispatcher fires an event with this attribute,
 * the BroadcastManager automatically broadcasts it to the specified channels.
 *
 * Usage:
 *   #[ShouldBroadcast(channels: ['orders'])]
 *   final class OrderShipped implements BroadcastEventInterface { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class ShouldBroadcast
{
    /**
     * @param list<string> $channels Default channel names (overridden by broadcastOn() if the event implements BroadcastEventInterface)
     * @param bool $toOthers If true, exclude the connection that triggered the event
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public array $channels = [],
        public bool $toOthers = false,
    ) {}
}
