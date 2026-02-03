<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Idempotency;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Idempotency\IdempotencyClaimStatus;
use Pulsar\Extension\Payments\Idempotency\InMemoryIdempotencyStore;

#[CoversClass(InMemoryIdempotencyStore::class)]
final class InMemoryIdempotencyStoreTest extends TestCase
{
    #[Test]
    public function claimNewKeyReturnsClaimed(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $claim = $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->resultPayload);
    }

    #[Test]
    public function claimSameKeyAndHashAfterCommitReturnsReplay(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);
        $store->commit('key-1', '{"result":"ok"}');

        $claim = $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);

        self::assertSame(IdempotencyClaimStatus::Replay, $claim->status);
        self::assertSame('{"result":"ok"}', $claim->resultPayload);
    }

    #[Test]
    public function claimSameKeyDifferentHashReturnsMismatch(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);
        $store->commit('key-1', '{"result":"ok"}');

        $claim = $store->claim('key-1', 'hash-different', 'createIntent', $now, 3600);

        self::assertSame(IdempotencyClaimStatus::Mismatch, $claim->status);
    }

    #[Test]
    public function claimInFlightKeyThrowsConcurrentClaim(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage('currently being processed');

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);
    }

    #[Test]
    public function releaseAllowsReclaim(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);
        $store->release('key-1');

        $claim = $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function expiredRecordTreatedAsNew(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable('@1700000000');

        $store->claim('key-1', 'hash-1', 'createIntent', $now, 60);
        $store->commit('key-1', '{"result":"old"}');

        $later = new DateTimeImmutable('@1700000100'); // After TTL
        $claim = $store->claim('key-1', 'hash-2', 'createIntent', $later, 60);

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function pruneRemovesExpiredRecords(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable('@1700000000');

        $store->claim('key-1', 'hash-1', 'op1', $now, 60);
        $store->commit('key-1', '{"result":"1"}');

        $store->claim('key-2', 'hash-2', 'op2', $now, 3600);
        $store->commit('key-2', '{"result":"2"}');

        $later = new DateTimeImmutable('@1700000100');
        $pruned = $store->prune($later);

        self::assertSame(1, $pruned);

        // key-1 should be gone (TTL 60s, pruned after 100s)
        $claim1 = $store->claim('key-1', 'hash-1', 'op1', $later, 60);
        self::assertSame(IdempotencyClaimStatus::Claimed, $claim1->status);

        // key-2 should still exist (TTL 3600s)
        $claim2 = $store->claim('key-2', 'hash-2', 'op2', $later, 3600);
        self::assertSame(IdempotencyClaimStatus::Replay, $claim2->status);
    }

    #[Test]
    public function commitClearsInFlightFlag(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable();

        $store->claim('key-1', 'hash-1', 'op', $now, 3600);
        $store->commit('key-1', '{"result":"ok"}');

        // Should not throw — in-flight was cleared by commit
        $claim = $store->claim('key-1', 'hash-1', 'op', $now, 3600);
        self::assertSame(IdempotencyClaimStatus::Replay, $claim->status);
    }
}
