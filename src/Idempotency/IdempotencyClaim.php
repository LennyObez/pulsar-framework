<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of an idempotency claim attempt.
 */
#[Api(since: '1.0.0')]
final readonly class IdempotencyClaim
{
    private function __construct(
        public IdempotencyClaimStatus $status,
        public ?string $resultPayload,
    ) {}

    #[NoDiscard]
    public static function replay(string $resultPayload): self
    {
        return new self(IdempotencyClaimStatus::Replay, $resultPayload);
    }

    #[NoDiscard]
    public static function claimed(): self
    {
        return new self(IdempotencyClaimStatus::Claimed, null);
    }

    #[NoDiscard]
    public static function mismatch(): self
    {
        return new self(IdempotencyClaimStatus::Mismatch, null);
    }
}
