<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Internal;

use Pulsar\Api\Internal;
use Pulsar\WebSocket\BroadcastManagerInterface;
use Pulsar\WebSocket\ChannelManager;
use Pulsar\WebSocket\WebSocketFrame;

use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * In-memory broadcast manager for single-server deployments.
 *
 * Sends messages directly to connections tracked in the channel manager.
 * For multi-server, use a Redis-backed adapter instead.
 */
#[Internal]
final class InMemoryBroadcastManager implements BroadcastManagerInterface
{
    /** @var array<string, callable(string): void> Connection ID → send callback */
    private array $senders = [];

    public function __construct(
        private readonly ChannelManager $channelManager,
    ) {}

    /**
     * Register a send callback for a connection.
     *
     * @param callable(string): void $sender Callback that sends raw data to the connection
     */
    public function registerSender(string $connectionId, callable $sender): void
    {
        $this->senders[$connectionId] = $sender;
    }

    /**
     * Remove a sender when the connection closes.
     */
    public function removeSender(string $connectionId): void
    {
        unset($this->senders[$connectionId]);
    }

    public function broadcast(string $channel, string $event, array $data): void
    {
        $this->broadcastExcept($channel, $event, $data, []);
    }

    public function broadcastExcept(string $channel, string $event, array $data, array $excludeConnectionIds): void
    {
        $subscribers = $this->channelManager->subscribers($channel);
        $payload = $this->encodeEvent($channel, $event, $data);

        foreach ($subscribers as $connectionId) {
            if (in_array($connectionId, $excludeConnectionIds, true)) {
                continue;
            }

            $this->sendRaw($connectionId, $payload);
        }
    }

    public function sendTo(string $connectionId, string $event, array $data): void
    {
        $payload = $this->encodeEvent('', $event, $data);
        $this->sendRaw($connectionId, $payload);
    }

    private function sendRaw(string $connectionId, string $frameData): void
    {
        $sender = $this->senders[$connectionId] ?? null;

        if ($sender !== null) {
            $sender($frameData);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeEvent(string $channel, string $event, array $data): string
    {
        $message = [
            'event' => $event,
            'data' => $data,
        ];

        if ($channel !== '') {
            $message['channel'] = $channel;
        }

        $json = json_encode(
            $message,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return WebSocketFrame::text($json)->encode();
    }
}
