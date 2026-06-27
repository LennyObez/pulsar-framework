<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\Exception\IdempotencyErrorKind;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Idempotency\IdempotencyClaimStatus;
use Pulsar\Idempotency\InMemoryIdempotencyStore;

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

        try {
            $store->claim('key-1', 'hash-1', 'createIntent', $now, 3600);
            self::fail('Expected IdempotencyException for concurrent claim');
        } catch (IdempotencyException $e) {
            // F22.19: assert kind tag is ConcurrentClaim so HTTP layers
            // can map this to 409 Conflict rather than 500.
            self::assertSame(IdempotencyErrorKind::ConcurrentClaim, $e->kind);
            self::assertStringContainsString('currently being processed', $e->getMessage());
        }
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

    #[Test]
    public function commitForNonExistentKeyDoesNothing(): void
    {
        $store = new InMemoryIdempotencyStore();

        // Should not throw — just silently clear the in-flight flag
        $store->commit('nonexistent', '{"result":"ok"}');

        // Key was never claimed, so a new claim should succeed
        $now = new DateTimeImmutable();
        $claim = $store->claim('nonexistent', 'hash-1', 'op', $now, 3600);
        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function pruneClearsInFlightForUncommittedExpiredClaim(): void
    {
        // Regression: prune() removed the record but left the in-flight
        // mutex orphaned, so the next claim() threw a spurious
        // ConcurrentClaim and locked the key forever.
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable('@1700000000');

        // Claim but never commit — the key stays in-flight.
        $store->claim('key-1', 'hash-1', 'op', $now, 1);

        // Prune past the (short) TTL while the key is still in-flight.
        $later = new DateTimeImmutable('@1700000010');
        self::assertSame(1, $store->prune($later));

        // The mutex must have been cleared alongside the record: a fresh
        // claim succeeds rather than throwing ConcurrentClaim.
        $claim = $store->claim('key-1', 'hash-1', 'op', $later, 60);
        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function claimRejectsZeroTtl(): void
    {
        $store = new InMemoryIdempotencyStore();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be a positive');

        $store->claim('key-1', 'hash-1', 'op', new DateTimeImmutable(), 0);
    }

    #[Test]
    public function claimRejectsNegativeTtl(): void
    {
        $store = new InMemoryIdempotencyStore();

        $this->expectException(InvalidArgumentException::class);

        $store->claim('key-1', 'hash-1', 'op', new DateTimeImmutable(), -5);
    }

    #[Test]
    public function pruneReturnsZeroForEmptyStore(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertSame(0, $store->prune(new DateTimeImmutable()));
    }

    #[Test]
    public function releaseNonexistentKeyDoesNotThrow(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->release('nonexistent');

        // Verify store still works normally after releasing nonexistent key
        $now = new DateTimeImmutable();
        $claim = $store->claim('key-1', 'hash-1', 'op', $now, 3600);
        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
    }

    #[Test]
    public function claimAfterExpiredRecordReclaimsWithDifferentHash(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable('@1700000000');

        $store->claim('key-1', 'hash-1', 'op1', $now, 10);
        $store->commit('key-1', '{"result":"first"}');

        // After TTL, key is treated as new even with different hash
        $later = new DateTimeImmutable('@1700000020');
        $claim = $store->claim('key-1', 'hash-new', 'op2', $later, 3600);

        self::assertSame(IdempotencyClaimStatus::Claimed, $claim->status);
        self::assertNull($claim->resultPayload);
    }

    #[Test]
    public function prunePreservesUnexpiredRecords(): void
    {
        $store = new InMemoryIdempotencyStore();
        $now = new DateTimeImmutable('@1700000000');

        $store->claim('short-lived', 'h1', 'op', $now, 60);
        $store->commit('short-lived', '{"r":"1"}');
        $store->claim('long-lived', 'h2', 'op', $now, 7200);
        $store->commit('long-lived', '{"r":"2"}');

        $later = new DateTimeImmutable('@1700000100');
        $pruned = $store->prune($later);

        self::assertSame(1, $pruned);

        // short-lived is gone → can reclaim
        $claim1 = $store->claim('short-lived', 'h1', 'op', $later, 60);
        self::assertSame(IdempotencyClaimStatus::Claimed, $claim1->status);

        // long-lived still exists → replay
        $claim2 = $store->claim('long-lived', 'h2', 'op', $later, 7200);
        self::assertSame(IdempotencyClaimStatus::Replay, $claim2->status);
    }
}
