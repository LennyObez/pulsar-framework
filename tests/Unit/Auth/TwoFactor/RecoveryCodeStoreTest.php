<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\ConsumeResult;
use Pulsar\Auth\TwoFactor\InMemoryRecoveryCodeStore;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;

#[CoversClass(InMemoryRecoveryCodeStore::class)]
#[CoversClass(RecoveryCodeSet::class)]
#[CoversClass(ConsumeResult::class)]
final class RecoveryCodeStoreTest extends TestCase
{
    private InMemoryRecoveryCodeStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryRecoveryCodeStore();
    }

    #[Test]
    public function loadSetReturnsNullWhenNotStored(): void
    {
        self::assertNull($this->store->loadSet('user-1'));
    }

    #[Test]
    public function storeAndLoadSetRoundTrip(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'abc123',
            codeHashes: ['hash1', 'hash2', 'hash3'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1000000,
        );

        $this->store->store('user-1', $set);
        $loaded = $this->store->loadSet('user-1');

        self::assertNotNull($loaded);
        self::assertSame('abc123', $loaded->setId);
        self::assertCount(3, $loaded->codeHashes);
        self::assertSame([], $loaded->usedIndices);
    }

    #[Test]
    public function consumeSucceedsForValidCode(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'abc123',
            codeHashes: ['hash1', 'hash2', 'hash3'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1000000,
        );

        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'hash2');

        self::assertTrue($result->consumed);
        self::assertSame(1, $result->codeIndex);
        self::assertSame(ConsumeReason::Consumed, $result->reason);
    }

    #[Test]
    public function consumeRejectsAlreadyUsedCode(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'abc123',
            codeHashes: ['hash1', 'hash2'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1000000,
        );

        $this->store->store('user-1', $set);

        $first = $this->store->consume('user-1', 'hash1');
        self::assertTrue($first->consumed);

        $second = $this->store->consume('user-1', 'hash1');
        self::assertFalse($second->consumed);
        self::assertSame(ConsumeReason::AlreadyUsed, $second->reason);
    }

    #[Test]
    public function consumeRejectsUnknownCodeHash(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'abc123',
            codeHashes: ['hash1'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1000000,
        );

        $this->store->store('user-1', $set);

        $result = $this->store->consume('user-1', 'unknown');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotFound, $result->reason);
    }

    #[Test]
    public function consumeReturnsNotEnrolledWhenNoSet(): void
    {
        $result = $this->store->consume('user-1', 'hash1');

        self::assertFalse($result->consumed);
        self::assertSame(ConsumeReason::NotEnrolled, $result->reason);
    }

    #[Test]
    public function recoveryCodeSetRemainingCount(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'abc123',
            codeHashes: ['h1', 'h2', 'h3'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1000000,
        );

        self::assertSame(2, $set->remainingCount());
        self::assertTrue($set->isUsed(0));
        self::assertFalse($set->isUsed(1));

        $updated = $set->withUsedIndex(1);
        self::assertSame(1, $updated->remainingCount());
    }
}
