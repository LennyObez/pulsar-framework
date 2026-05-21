<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an event class for WebSocket broadcasting.
 *
 * Usage:
 *   #[BroadcastEvent(channel: 'orders.{orderId}')]
 *   final class OrderUpdated { ... }
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class BroadcastEvent
{
    /**
     * @param string $channel Channel pattern (use {param} for dynamic segments)
     * @param bool $private Whether this is a private channel
     * @param bool $presence Whether this is a presence channel
     */
    public function __construct(
        public string $channel,
        public bool $private = false,
        public bool $presence = false,
    ) {}

    /**
     * Resolve the channel name by replacing placeholders with actual values.
     *
     * @param array<string, string> $params Parameter name → value
     */
    public function resolveChannel(array $params): string
    {
        $channel = $this->channel;

        foreach ($params as $key => $value) {
            $channel = str_replace('{' . $key . '}', $value, $channel);
        }

        if ($this->presence) {
            return 'presence-' . $channel;
        }

        if ($this->private) {
            return 'private-' . $channel;
        }

        return $channel;
    }
}
