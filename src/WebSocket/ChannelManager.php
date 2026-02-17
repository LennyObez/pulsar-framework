<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function str_starts_with;

/**
 * Manages WebSocket channels and their subscribers.
 *
 * Supports three channel types:
 * - Public channels: anyone can subscribe
 * - Private channels (prefix "private-"): require authentication
 * - Presence channels (prefix "presence-"): track who is online
 */
#[Api(since: '1.0.0')]
final class ChannelManager
{
    /** @var array<string, list<string>> Channel name → list of connection IDs */
    private array $subscriptions = [];

    /** @var array<string, array<string, array<string, mixed>>> Presence channel → connection ID → user info */
    private array $presenceData = [];

    /**
     * Subscribe a connection to a channel.
     */
    public function subscribe(string $channel, string $connectionId): void
    {
        if (!isset($this->subscriptions[$channel])) {
            $this->subscriptions[$channel] = [];
        }

        if (!in_array($connectionId, $this->subscriptions[$channel], true)) {
            $this->subscriptions[$channel][] = $connectionId;
        }
    }

    /**
     * Subscribe to a presence channel with user info.
     *
     * @param array<string, mixed> $userInfo
     */
    public function subscribePresence(string $channel, string $connectionId, array $userInfo): void
    {
        $this->subscribe($channel, $connectionId);

        if (!isset($this->presenceData[$channel])) {
            $this->presenceData[$channel] = [];
        }

        $this->presenceData[$channel][$connectionId] = $userInfo;
    }

    /**
     * Unsubscribe a connection from a channel.
     */
    public function unsubscribe(string $channel, string $connectionId): void
    {
        if (!isset($this->subscriptions[$channel])) {
            return;
        }

        $this->subscriptions[$channel] = array_values(array_filter(
            $this->subscriptions[$channel],
            static fn(string $id): bool => $id !== $connectionId,
        ));

        if ($this->subscriptions[$channel] === []) {
            unset($this->subscriptions[$channel]);
        }

        // Clean up presence data
        if (isset($this->presenceData[$channel][$connectionId])) {
            unset($this->presenceData[$channel][$connectionId]);

            if ($this->presenceData[$channel] === []) {
                unset($this->presenceData[$channel]);
            }
        }
    }

    /**
     * Remove a connection from all channels.
     *
     * @return list<string> Channels the connection was removed from
     */
    public function unsubscribeAll(string $connectionId): array
    {
        $removedFrom = [];

        foreach ($this->subscriptions as $channel => $connections) {
            if (in_array($connectionId, $connections, true)) {
                $this->unsubscribe($channel, $connectionId);
                $removedFrom[] = $channel;
            }
        }

        return $removedFrom;
    }

    /**
     * Get all connection IDs subscribed to a channel.
     *
     * @return list<string>
     */
    public function subscribers(string $channel): array
    {
        return $this->subscriptions[$channel] ?? [];
    }

    /**
     * Get the subscriber count for a channel.
     */
    public function subscriberCount(string $channel): int
    {
        return count($this->subscriptions[$channel] ?? []);
    }

    /**
     * Whether a channel has any subscribers.
     */
    public function hasSubscribers(string $channel): bool
    {
        return isset($this->subscriptions[$channel]) && $this->subscriptions[$channel] !== [];
    }

    /**
     * Get all active channel names.
     *
     * @return list<string>
     */
    public function activeChannels(): array
    {
        return array_keys($this->subscriptions);
    }

    /**
     * Get presence data for a channel.
     *
     * @return array<string, array<string, mixed>> Connection ID → user info
     */
    public function presenceMembers(string $channel): array
    {
        return $this->presenceData[$channel] ?? [];
    }

    /**
     * Whether a channel requires authentication (private or presence).
     */
    public static function requiresAuth(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    /**
     * Whether a channel is a presence channel.
     */
    public static function isPresenceChannel(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }

    /**
     * Whether a channel is a private channel.
     */
    public static function isPrivateChannel(string $channel): bool
    {
        return str_starts_with($channel, 'private-');
    }
}
