<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\WebhookException;
use Pulsar\Extension\Payments\Webhook\InMemoryWebhookEventLog;
use Pulsar\Extension\Payments\Webhook\WebhookClaimStatus;

#[CoversClass(InMemoryWebhookEventLog::class)]
final class InMemoryWebhookEventLogTest extends TestCase
{
    #[Test]
    public function claimNewEventReturnsClaimed(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $claim = $log->claim('evt_1', $now, 3600);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->processedAt);
    }

    #[Test]
    public function claimProcessedEventReturnsReplay(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $log->claim('evt_1', $now, 3600);
        $log->commit('evt_1');

        $claim = $log->claim('evt_1', $now, 3600);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
        self::assertNotNull($claim->processedAt);
    }

    #[Test]
    public function claimInFlightEventThrowsConcurrentClaim(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $log->claim('evt_1', $now, 3600);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('currently being processed');

        $log->claim('evt_1', $now, 3600);
    }

    #[Test]
    public function releaseAllowsReclaim(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $log->claim('evt_1', $now, 3600);
        $log->release('evt_1');

        $claim = $log->claim('evt_1', $now, 3600);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function commitThenClaimReturnsReplay(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $log->claim('evt_1', $now, 3600);
        $log->commit('evt_1');

        $claim = $log->claim('evt_1', $now, 3600);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
    }

    #[Test]
    public function pruneRemovesExpiredRecords(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable('@1700000000');

        $log->claim('evt_1', $now, 60);
        $log->commit('evt_1');

        $later = new DateTimeImmutable('@1800000000');
        $pruned = $log->prune($later);

        self::assertSame(1, $pruned);

        // Should be claimable again after prune
        $claim = $log->claim('evt_1', $later, 3600);
        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
    }
}
