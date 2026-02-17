<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;

final class InMemoryRecoveryCodeStoreTest extends TestCase
{
    private InMemoryRecoveryCodeStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryRecoveryCodeStore();
    }

    #[Test]
    public function load_set_returns_null_for_unknown_identity(): void
    {
        self::assertNull($this->store->loadSet('unknown'));
    }

    #[Test]
    public function store_and_load_round_trips(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1', 'hash2'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $this->store->store('user-1', $set);

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame('set-1', $loaded->setId);
        self::assertSame(['hash0', 'hash1', 'hash2'], $loaded->codeHashes);
    }

    #[Test]
    public function consume_returns_not_enrolled_for_unknown_identity(): void
    {
        $result = $this->store->consume('unknown', 'hash0');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function consume_returns_not_found_for_wrong_hash(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'wrong-hash');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotFound, $result->reason);
    }

    #[Test]
    public function consume_succeeds_for_valid_unused_code(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'hash0');

        self::assertTrue($result->consumed);
        self::assertSame(0, $result->codeIndex);
        self::assertSame(ConsumeReason::Consumed, $result->reason);
    }

    #[Test]
    public function consume_returns_already_used_for_consumed_code(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $this->store->store('user-1', $set);
        $this->store->consume('user-1', 'hash0');

        $result = $this->store->consume('user-1', 'hash0');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::AlreadyUsed, $result->reason);
    }

    #[Test]
    public function consume_marks_code_as_used_in_stored_set(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1', 'hash2'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $this->store->store('user-1', $set);
        $this->store->consume('user-1', 'hash1');

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->remainingCount());
        self::assertTrue($loaded->isUsed(1));
    }

    #[Test]
    public function store_overwrites_previous_set(): void
    {
        $set1 = new RecoveryCodeSet('set-1', ['h0'], [], 2, 1700000000);
        $set2 = new RecoveryCodeSet('set-2', ['h0', 'h1'], [], 2, 1700000001);

        $this->store->store('user-1', $set1);
        $this->store->store('user-1', $set2);

        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame('set-2', $loaded->setId);
    }
}
