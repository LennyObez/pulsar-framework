<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Adapter;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Adapter\InMemoryChallengeStore;
use Pulsar\Testing\Clock\TestClock;

#[CoversClass(InMemoryChallengeStore::class)]
final class InMemoryChallengeStoreTest extends TestCase
{
    /**
     * The claim is the whole contract: the first caller gets it, the second does
     * not. A store that returned `true` twice would let both attempts through and
     * report no error while doing it.
     */
    #[Test]
    public function theFirstCallClaimsTheChallengeAndTheSecondIsRefused(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);
        $expiresAt = $clock->now()->modify('+300 seconds');

        self::assertTrue($store->consume('challenge-a', $expiresAt));
        self::assertFalse($store->consume('challenge-a', $expiresAt));
        self::assertFalse($store->consume('challenge-a', $expiresAt));
    }

    /**
     * Claiming one challenge must not claim another, or every ceremony after the
     * first would be refused.
     */
    #[Test]
    public function claimingOneChallengeLeavesOthersUnclaimed(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);
        $expiresAt = $clock->now()->modify('+300 seconds');

        self::assertTrue($store->consume('challenge-a', $expiresAt));
        self::assertTrue($store->consume('challenge-b', $expiresAt));
        self::assertFalse($store->consume('challenge-a', $expiresAt));
    }

    /**
     * Records are dropped once the challenge is past the instant its expiry names,
     * so a long-lived worker does not accumulate one entry per challenge it ever
     * issued.
     *
     * Dropping the record cannot reopen a replay window: a challenge past its TTL
     * is refused on freshness by the ceremony before consumption is ever
     * attempted. The `true` on the second call below is therefore the pruning
     * working, not a replay being admitted — reaching that call in production
     * would already be a bug in the ceremony, not in this store.
     */
    #[Test]
    public function aRecordIsDroppedOnceTheChallengeIsPastItsExpiry(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);
        $expiresAt = $clock->now()->modify('+300 seconds');

        self::assertTrue($store->consume('challenge-a', $expiresAt));

        $clock->advance(seconds: 301);

        self::assertTrue($store->consume('challenge-a', $expiresAt));
    }

    /**
     * Pruning is exclusive at the boundary. A record whose expiry equals the
     * current second is still held, because the ceremony's own window is
     * inclusive at that second: dropping it one tick early would admit a replay
     * the ceremony still considers fresh.
     */
    #[Test]
    public function aRecordSurvivesTheExactSecondOfItsExpiry(): void
    {
        $clock = TestClock::at('2026-03-01T12:00:00+00:00');
        $store = new InMemoryChallengeStore($clock);
        $expiresAt = $clock->now()->modify('+300 seconds');

        self::assertTrue($store->consume('challenge-a', $expiresAt));

        $clock->advance(seconds: 300);

        self::assertFalse($store->consume('challenge-a', $expiresAt));
    }

    /**
     * Without a clock the store falls back to the system clock rather than
     * refusing to prune. Records stamped far in the future are held, which is the
     * conservative direction: a store that pruned on an absent clock would drop
     * live records.
     */
    #[Test]
    public function theStoreWorksWithNoClockInjected(): void
    {
        $store = new InMemoryChallengeStore();
        $expiresAt = new DateTimeImmutable('+1 hour');

        self::assertTrue($store->consume('challenge-a', $expiresAt));
        self::assertFalse($store->consume('challenge-a', $expiresAt));
    }
}
