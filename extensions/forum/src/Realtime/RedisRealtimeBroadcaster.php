<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Realtime;

use Override;
use Pulsar\Api\Internal;
use Redis;

use function array_unique;
use function array_values;
use function is_string;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Redis-backed real-time broadcaster using Redis Pub/Sub and sorted sets.
 *
 * Events are published to a Redis Pub/Sub channel for cross-worker delivery
 * and stored in a capped list for late-joining clients. Presence is tracked
 * via sorted sets with timestamp scores for automatic expiry.
 */
#[Internal(reason: 'Redis broadcaster; use RealtimeBroadcasterInterface')]
final class RedisRealtimeBroadcaster implements RealtimeBroadcasterInterface
{
    private const int MAX_EVENTS_PER_CHANNEL = 100;
    private const int PRESENCE_TTL_SECONDS = 300;
    private const string CHANNEL_PREFIX = 'forum:rt:';
    private const string EVENTS_PREFIX = 'forum:events:';
    private const string PRESENCE_PREFIX = 'forum:presence:';

    public function __construct(
        private readonly Redis $redis,
    ) {}

    #[Override]
    public function broadcast(RealtimeEvent $event): void
    {
        $channel = self::CHANNEL_PREFIX . $event->channelId;
        $payload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);

        // Publish to Redis Pub/Sub for real-time delivery
        $this->redis->publish($channel, $payload);

        // Store in a capped list for late-joining clients
        $eventsKey = self::EVENTS_PREFIX . $event->channelId;
        $this->redis->rPush($eventsKey, $payload);
        $this->redis->lTrim($eventsKey, -self::MAX_EVENTS_PER_CHANNEL, -1);
        $this->redis->expire($eventsKey, 3600);
    }

    #[Override]
    public function trackPresence(string $channelId, string $userId): void
    {
        $key = self::PRESENCE_PREFIX . $channelId;
        $this->redis->zAdd($key, time(), $userId);
        $this->redis->expire($key, self::PRESENCE_TTL_SECONDS * 2);
    }

    #[Override]
    public function removePresence(string $channelId, string $userId): void
    {
        $key = self::PRESENCE_PREFIX . $channelId;
        $this->redis->zRem($key, $userId);
    }

    #[Override]
    public function getPresence(string $channelId): array
    {
        $key = self::PRESENCE_PREFIX . $channelId;
        $cutoff = time() - self::PRESENCE_TTL_SECONDS;

        // Remove stale entries
        $this->redis->zRemRangeByScore($key, '-inf', (string) $cutoff);

        // Return active user IDs
        $members = $this->redis->zRangeByScore($key, (string) ($cutoff + 1), '+inf');

        if ($members === false) {
            return [];
        }

        /** @var list<string> $result */
        $result = [];

        foreach ($members as $member) {
            if (is_string($member)) {
                $result[] = $member;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Get recent events for a channel since a given index.
     *
     * @return list<RealtimeEvent>
     */
    public function getRecentEvents(string $channelId, int $sinceIndex = 0): array
    {
        $key = self::EVENTS_PREFIX . $channelId;
        // Treat the Redis client return as an untyped boundary; entries are
        // decoded defensively below regardless of the stub's element typing.
        /** @var array<int, mixed>|false $entries */
        $entries = $this->redis->lRange($key, $sinceIndex, -1);

        if ($entries === false || $entries === []) {
            return [];
        }

        $events = [];

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            /** @var array{type: string, channel: string, payload: string, user_id: string, timestamp: string}|null $data */
            $data = json_decode($entry, true);

            if ($data === null) {
                continue;
            }

            $type = RealtimeEventType::tryFrom($data['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $events[] = new RealtimeEvent(
                type: $type,
                channelId: $data['channel'] ?? $channelId,
                payload: $data['payload'] ?? '',
                userId: $data['user_id'] ?? '',
            );
        }

        return $events;
    }
}
