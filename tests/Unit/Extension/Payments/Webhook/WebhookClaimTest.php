<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookClaim;
use Pulsar\Webhook\WebhookClaimStatus;

#[CoversClass(WebhookClaim::class)]
final class WebhookClaimTest extends TestCase
{
    #[Test]
    public function replayHasStatusAndProcessedAt(): void
    {
        $processedAt = new DateTimeImmutable('2025-01-01T00:00:00Z');
        $claim = WebhookClaim::replay($processedAt);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
        self::assertSame($processedAt, $claim->processedAt);
    }

    #[Test]
    public function claimedHasStatusAndNullProcessedAt(): void
    {
        $claim = WebhookClaim::claimed();

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->processedAt);
    }
}
