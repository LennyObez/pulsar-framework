<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Webhook;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a webhook event claim attempt.
 */
#[Api]
final readonly class WebhookClaim
{
    private function __construct(
        public WebhookClaimStatus $status,
        public ?DateTimeImmutable $processedAt,
    ) {}

    #[NoDiscard]
    public static function replay(DateTimeImmutable $processedAt): self
    {
        return new self(WebhookClaimStatus::Replay, $processedAt);
    }

    #[NoDiscard]
    public static function claimed(): self
    {
        return new self(WebhookClaimStatus::Claimed, null);
    }
}
