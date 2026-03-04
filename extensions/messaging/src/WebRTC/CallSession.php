<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\WebRTC;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Messaging\Domain\CallStatus;

/**
 * Tracks an active or completed WebRTC call session.
 */
#[Api(since: '1.0.0')]
final class CallSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $callerId,
        public readonly string $calleeId,
        public readonly string $conversationId,
        public CallStatus $status,
        public readonly DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $answeredAt = null,
        public ?DateTimeImmutable $endedAt = null,
    ) {}

    public function answer(): void
    {
        $this->status = CallStatus::Active;
        $this->answeredAt = new DateTimeImmutable();
    }

    public function end(): void
    {
        $this->status = CallStatus::Ended;
        $this->endedAt = new DateTimeImmutable();
    }

    public function reject(): void
    {
        $this->status = CallStatus::Rejected;
        $this->endedAt = new DateTimeImmutable();
    }

    public function markMissed(): void
    {
        $this->status = CallStatus::Missed;
        $this->endedAt = new DateTimeImmutable();
    }

    /**
     * Duration in seconds, or null if call hasn't been answered.
     */
    public function durationSeconds(): ?int
    {
        if ($this->answeredAt === null) {
            return null;
        }

        $end = $this->endedAt ?? new DateTimeImmutable();

        return $end->getTimestamp() - $this->answeredAt->getTimestamp();
    }

    public function isActive(): bool
    {
        return $this->status === CallStatus::Active;
    }
}
