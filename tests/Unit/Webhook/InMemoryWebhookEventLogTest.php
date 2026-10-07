<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\InMemoryWebhookEventLog;
use Pulsar\Webhook\WebhookClaimStatus;

#[CoversClass(InMemoryWebhookEventLog::class)]
final class InMemoryWebhookEventLogTest extends TestCase
{
    #[Test]
    public function claimNewEventReturnsClaimed(): void
    {
        $log = new InMemoryWebhookEventLog();
        $claim = $log->claim('evt-1', new DateTimeImmutable(), 3600);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->processedAt);
    }

    #[Test]
    public function claimAfterCommitReturnsReplay(): void
    {
        $log = new InMemoryWebhookEventLog();
        $log->claim('evt-1', new DateTimeImmutable(), 3600);
        $log->commit('evt-1');

        $claim = $log->claim('evt-1', new DateTimeImmutable(), 3600);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
        self::assertNotNull($claim->processedAt);
    }

    #[Test]
    public function claimInFlightThrowsConcurrentClaim(): void
    {
        $log = new InMemoryWebhookEventLog();
        $log->claim('evt-1', new DateTimeImmutable(), 3600);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('currently being processed');

        $log->claim('evt-1', new DateTimeImmutable(), 3600);
    }

    #[Test]
    public function releaseAllowsReclaim(): void
    {
        $log = new InMemoryWebhookEventLog();
        $log->claim('evt-1', new DateTimeImmutable(), 3600);
        $log->release('evt-1');

        $claim = $log->claim('evt-1', new DateTimeImmutable(), 3600);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function pruneRemovesExpiredRecords(): void
    {
        $log = new InMemoryWebhookEventLog();
        $now = new DateTimeImmutable();

        $log->claim('evt-1', $now, 60);
        $log->commit('evt-1');

        // commit() sets expiry to real now + 72h, so prune far enough in the future
        $farFuture = $now->modify('+400000 seconds');
        $pruned = $log->prune($farFuture);

        self::assertSame(1, $pruned);
    }
}
