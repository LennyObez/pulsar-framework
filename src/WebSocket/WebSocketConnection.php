<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;
use Pulsar\WebSocket\Exception\WebSocketException;
use Pulsar\WebSocket\Internal\NullFrameSink;

use function array_filter;
use function array_values;
use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Represents an active WebSocket connection.
 *
 * A connection is created by the server immediately after a successful
 * upgrade handshake, and is passed to the `MessageHandlerInterface` lifecycle
 * callbacks. Its identity (`$id`) is a server-assigned UUID v7 so that
 * connection IDs are time-ordered and cross-process monotonic.
 *
 * The class carries two distinct bags of state:
 *  - Authenticated identity (user id + session id) — written once by the
 *    inbound authentication middleware, read thereafter.
 *  - Opaque per-connection metadata (`$metadata`) — free-form application
 *    state a handler may attach.
 *
 * Outbound writes are routed through a `FrameSinkInterface`, which lets the
 * test harness swap in an in-memory sink.
 * @api
 */
#[Api(since: '1.0.0')]
final class WebSocketConnection
{
    /** @var list<string> Channels this connection is subscribed to */
    private array $channels = [];

    /** @var array<string, mixed> Arbitrary metadata for this connection */
    private array $metadata = [];

    private readonly FrameSinkInterface $sink;

    /**
     * The sink parameter defaults to a `NullFrameSink` so that existing call
     * sites that build a `WebSocketConnection` purely for authorization
     * (`Broadcasting\BroadcastAuthController`) or for tests do not need to
     * wire a transport. Real server implementations must inject a sink.
     */
    public function __construct(
        public readonly string $id,
        public readonly float $connectedAt,
        private ?string $userId = null,
        ?FrameSinkInterface $sink = null,
        private ?string $sessionId = null,
    ) {
        $this->sink = $sink ?? new NullFrameSink();
    }

    /**
     * Get the authenticated user ID, if any.
     */
    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Get the bound HTTP session ID, if any.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Bind an authenticated identity to the connection. Typically called by
     * `Pulsar\WebSocket\Middleware\AuthenticateMiddleware` after verifying
     * a session cookie or bearer token presented during the upgrade handshake.
     *
     * Rebinding a different user throws — a connection's identity is immutable
     * once established, consistent with PSR-7 semantics of request-scoped state.
     */
    public function authenticate(string $userId, ?string $sessionId = null): void
    {
        if ($this->userId !== null && $this->userId !== $userId) {
            throw WebSocketException::identityAlreadyBound($this->id, $this->userId, $userId);
        }

        $this->userId = $userId;
        $this->sessionId = $sessionId ?? $this->sessionId;
    }

    /**
     * Whether this connection is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Subscribe to a channel. Idempotent — subscribing twice is a no-op.
     */
    public function subscribe(string $channel): void
    {
        if (!in_array($channel, $this->channels, true)) {
            $this->channels[] = $channel;
        }
    }

    /**
     * Unsubscribe from a channel. Idempotent.
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

    /**
     * Send a text frame to this connection. Payload is JSON-encoded with
     * `{channel, payload}` envelope.
     *
     * Discarded silently if the transport has closed — check `isOpen()` if
     * the caller needs to react.
     *
     * @param array<string, mixed> $payload
     */
    public function send(string $channel, array $payload): void
    {
        $envelope = json_encode(
            ['channel' => $channel, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->sink->push($this, WebSocketFrame::text($envelope));
    }

    /**
     * Send a raw frame to this connection. Prefer `send()` for application
     * payloads — this is a lower-level escape hatch for custom protocols
     * (binary, non-enveloped text).
     */
    public function sendFrame(WebSocketFrame $frame): void
    {
        $this->sink->push($this, $frame);
    }

    /**
     * Initiate an orderly close. `$code` must be one of `WebSocketCloseCode`
     * or a valid application-defined code in the range 3000-4999 per
     * RFC 6455 Section 7.4.2.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->sink->close($this, $code, $reason);
    }

    /**
     * Whether the underlying transport still accepts writes.
     */
    public function isOpen(): bool
    {
        return $this->sink->isOpen($this);
    }
}
