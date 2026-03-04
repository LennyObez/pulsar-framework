<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebRTC;

use Pulsar\Api\Api;

use function is_array;

/**
 * DTO for WebRTC signaling messages (offer/answer/ICE candidates).
 */
#[Api(since: '1.0.0')]
final readonly class SignalingMessage
{
    /**
     * @param string $type Message type: 'offer', 'answer', 'ice-candidate', 'bye'
     * @param string $fromUserId Sender user ID
     * @param string $toUserId Target user ID
     * @param string $callId Call session identifier
     * @param array<string, mixed> $payload SDP or ICE candidate data
     */
    public function __construct(
        public string $type,
        public string $fromUserId,
        public string $toUserId,
        public string $callId,
        public array $payload,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            fromUserId: (string) ($data['from_user_id'] ?? ''),
            toUserId: (string) ($data['to_user_id'] ?? ''),
            callId: (string) ($data['call_id'] ?? ''),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'from_user_id' => $this->fromUserId,
            'to_user_id' => $this->toUserId,
            'call_id' => $this->callId,
            'payload' => $this->payload,
        ];
    }

    public function isOffer(): bool
    {
        return $this->type === 'offer';
    }

    public function isAnswer(): bool
    {
        return $this->type === 'answer';
    }

    public function isIceCandidate(): bool
    {
        return $this->type === 'ice-candidate';
    }

    public function isBye(): bool
    {
        return $this->type === 'bye';
    }
}
