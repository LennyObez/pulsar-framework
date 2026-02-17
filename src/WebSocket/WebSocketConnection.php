<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

use function in_array;

/**
 * Represents an active WebSocket connection.
 */
#[Api(since: '1.0.0')]
final class WebSocketConnection
{
    /** @var list<string> Channels this connection is subscribed to */
    private array $channels = [];

    /** @var array<string, mixed> Arbitrary metadata for this connection */
    private array $metadata = [];

    public function __construct(
        public readonly string $id,
        public readonly float $connectedAt,
        private ?string $userId = null,
    ) {}

    /**
     * Get the authenticated user ID, if any.
     */
    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Set the authenticated user ID.
     */
    public function authenticate(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Whether this connection is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Subscribe to a channel.
     */
    public function subscribe(string $channel): void
    {
        if (!in_array($channel, $this->channels, true)) {
            $this->channels[] = $channel;
        }
    }

    /**
     * Unsubscribe from a channel.
     */
    public function unsubscribe(string $channel): void
    {
        $this->channels = array_values(array_filter(
            $this->channels,
            static fn(string $ch): bool => $ch !== $channel,
        ));
    }

    /**
     * Whether this connection is subscribed to a channel.
     */
    public function isSubscribedTo(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /**
     * Get all subscribed channels.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Set metadata on the connection.
     */
    public function setMeta(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get metadata from the connection.
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
