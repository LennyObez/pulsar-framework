<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Realtime;

use Override;
use Pulsar\Api\Internal;

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function time;

/**
 * In-memory SSE broadcaster for real-time forum events.
 *
 * In production, this would be backed by Redis pub/sub or a dedicated
 * WebSocket server. This implementation stores presence and recent events
 * in-memory for single-process deployments.
 *
 * @psalm-api Bound to RealtimeBroadcasterInterface in the forum service
 *            provider for in-memory/dev deployments; container-resolved.
 */
#[Internal(reason: 'SSE broadcaster; use RealtimeBroadcasterInterface')]
final class SseRealtimeBroadcaster implements RealtimeBroadcasterInterface
{
    /**
     * Recent events buffer per channel. Capped at 100 events per channel.
     *
     * @var array<string, list<RealtimeEvent>>
     */
    private array $recentEvents = [];

    /**
     * @var array<string, list<string>> Channel ID => list of user IDs
     */
    private array $presence = [];

    /**
     * @var array<string, int> Channel:UserId => last seen timestamp
     */
    private array $presenceTimestamps = [];

    private const int MAX_EVENTS_PER_CHANNEL = 100;
    private const int PRESENCE_TTL_SECONDS = 300;

    #[Override]
    public function broadcast(RealtimeEvent $event): void
    {
        $channel = $event->channelId;

        if (!isset($this->recentEvents[$channel])) {
            $this->recentEvents[$channel] = [];
        }

        $this->recentEvents[$channel][] = $event;

        // Trim to max buffer size
        if (count($this->recentEvents[$channel]) > self::MAX_EVENTS_PER_CHANNEL) {
            $this->recentEvents[$channel] = array_slice(
                $this->recentEvents[$channel],
                -self::MAX_EVENTS_PER_CHANNEL,
            );
        }
    }

    #[Override]
    public function trackPresence(string $channelId, string $userId): void
    {
        if (!isset($this->presence[$channelId])) {
            $this->presence[$channelId] = [];
        }

        if (!in_array($userId, $this->presence[$channelId], true)) {
            $this->presence[$channelId][] = $userId;
        }

        $this->presenceTimestamps[$channelId . ':' . $userId] = time();
    }

    #[Override]
    public function removePresence(string $channelId, string $userId): void
    {
        if (!isset($this->presence[$channelId])) {
            return;
        }

        $this->presence[$channelId] = array_values(
            array_filter(
                $this->presence[$channelId],
                static fn(string $id): bool => $id !== $userId,
            ),
        );

        unset($this->presenceTimestamps[$channelId . ':' . $userId]);
    }

    #[Override]
    public function getPresence(string $channelId): array
    {
        $this->expireStalePresence($channelId);

        return $this->presence[$channelId] ?? [];
    }

    /**
     * Get recent events for a channel since a given sequence.
     *
     * @return list<RealtimeEvent>
     */
    public function getRecentEvents(string $channelId, int $sinceIndex = 0): array
    {
        $events = $this->recentEvents[$channelId] ?? [];

        if ($sinceIndex >= count($events)) {
            return [];
        }

        return array_slice($events, $sinceIndex);
    }

    /**
     * Remove users whose presence timestamps have expired.
     */
    private function expireStalePresence(string $channelId): void
    {
        if (!isset($this->presence[$channelId])) {
            return;
        }

        $now = time();
        $active = [];

        foreach ($this->presence[$channelId] as $userId) {
            $key = $channelId . ':' . $userId;
            $lastSeen = $this->presenceTimestamps[$key] ?? 0;

            if ($now - $lastSeen <= self::PRESENCE_TTL_SECONDS) {
                $active[] = $userId;
            } else {
                unset($this->presenceTimestamps[$key]);
            }
        }

        $this->presence[$channelId] = array_values(array_unique($active));
    }
}
