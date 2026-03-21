<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Realtime;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An event broadcast to connected clients via SSE/WebSocket.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RealtimeEvent
{
    public function __construct(
        public RealtimeEventType $type,
        public string $channelId,
        public string $payload,
        public string $userId = '',
        public DateTimeImmutable $timestamp = new DateTimeImmutable(),
    ) {}

    /**
     * Serialize to SSE format.
     */
    public function toSse(): string
    {
        return "event: {$this->type->value}\ndata: {$this->payload}\n\n";
    }

    /**
     * @return array{type: string, channel: string, payload: string, user_id: string, timestamp: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'channel' => $this->channelId,
            'payload' => $this->payload,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
