<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\WebhookClaim;
use Pulsar\Webhook\WebhookClaimStatus;
use Pulsar\Webhook\WebhookEventLogInterface;

#[CoversNothing]
final class WebhookEventLogInterfaceTest extends TestCase
{
    private function createInMemoryLog(): WebhookEventLogInterface
    {
        return new class implements WebhookEventLogInterface {
            /** @var array<string, array{status: string, processedAt: DateTimeImmutable}> */
            private array $events = [];

            public function claim(string $eventId, DateTimeImmutable $now, int $ttlSeconds): WebhookClaim
            {
                if (isset($this->events[$eventId])) {
                    if ($this->events[$eventId]['status'] === 'processing') {
                        throw WebhookException::concurrentClaim($eventId);
                    }

                    return WebhookClaim::replay($this->events[$eventId]['processedAt']);
                }

                $this->events[$eventId] = ['status' => 'processing', 'processedAt' => $now];

                return WebhookClaim::claimed();
            }

            public function commit(string $eventId): void
            {
                if (isset($this->events[$eventId])) {
                    $this->events[$eventId]['status'] = 'committed';
                }
            }

            public function release(string $eventId): void
            {
                unset($this->events[$eventId]);
            }

            public function prune(DateTimeImmutable $before): int
            {
                $count = 0;
                foreach ($this->events as $id => $event) {
                    if ($event['processedAt'] < $before) {
                        unset($this->events[$id]);
                        $count++;
                    }
                }

                return $count;
            }
        };
    }

    #[Test]
    public function claimReturnsClaimedForNewEvent(): void
    {
        $log = $this->createInMemoryLog();
        $now = new DateTimeImmutable('2026-01-15 10:00:00');

        $claim = $log->claim('evt-001', $now, 300);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->processedAt);
    }

    #[Test]
    public function claimReturnsReplayForCommittedEvent(): void
    {
        $log = $this->createInMemoryLog();
        $now = new DateTimeImmutable('2026-01-15 10:00:00');

        $log->claim('evt-001', $now, 300);
        $log->commit('evt-001');

        $claim = $log->claim('evt-001', $now, 300);

        self::assertSame(WebhookClaimStatus::Replay, $claim->status);
        self::assertNotNull($claim->processedAt);
    }

    #[Test]
    public function claimThrowsForConcurrentProcessing(): void
    {
        $log = $this->createInMemoryLog();
        $now = new DateTimeImmutable('2026-01-15 10:00:00');

        $log->claim('evt-001', $now, 300);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('currently being processed');
        $log->claim('evt-001', $now, 300);
    }

    #[Test]
    public function releaseAllowsReClaim(): void
    {
        $log = $this->createInMemoryLog();
        $now = new DateTimeImmutable('2026-01-15 10:00:00');

        $log->claim('evt-001', $now, 300);
        $log->release('evt-001');

        $claim = $log->claim('evt-001', $now, 300);

        self::assertSame(WebhookClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function pruneRemovesOldEntries(): void
    {
        $log = $this->createInMemoryLog();

        $old = new DateTimeImmutable('2026-01-01 00:00:00');
        $recent = new DateTimeImmutable('2026-01-15 00:00:00');
        $cutoff = new DateTimeImmutable('2026-01-10 00:00:00');

        $log->claim('evt-old', $old, 300);
        $log->commit('evt-old');
        $log->claim('evt-recent', $recent, 300);
        $log->commit('evt-recent');

        $pruned = $log->prune($cutoff);

        self::assertSame(1, $pruned);
    }

    #[Test]
    public function pruneReturnsZeroWhenNothingToPrune(): void
    {
        $log = $this->createInMemoryLog();

        $pruned = $log->prune(new DateTimeImmutable('2020-01-01'));

        self::assertSame(0, $pruned);
    }
}
