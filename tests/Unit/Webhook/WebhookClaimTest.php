<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

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
    public function replayCreatesWithStatusAndProcessedAt(): void
    {
        $processedAt = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $claim = WebhookClaim::replay($processedAt);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
        self::assertSame($processedAt, $claim->processedAt);
    }

    #[Test]
    public function claimedCreatesWithStatusAndNullProcessedAt(): void
    {
        $claim = WebhookClaim::claimed();

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->processedAt);
    }
}
