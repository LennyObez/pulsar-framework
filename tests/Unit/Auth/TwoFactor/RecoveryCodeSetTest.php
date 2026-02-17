<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;

final class RecoveryCodeSetTest extends TestCase
{
    #[Test]
    public function is_used_returns_false_for_unused_index(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1', 'hash2'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        self::assertFalse($set->isUsed(1));
        self::assertFalse($set->isUsed(2));
    }

    #[Test]
    public function is_used_returns_true_for_consumed_index(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        self::assertTrue($set->isUsed(0));
    }

    #[Test]
    public function with_used_index_returns_new_set_with_index_added(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1', 'hash2'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $updated = $set->withUsedIndex(1);

        self::assertNotSame($set, $updated);
        self::assertFalse($set->isUsed(1));
        self::assertTrue($updated->isUsed(1));
        self::assertSame('set-1', $updated->setId);
        self::assertSame(2, $updated->algorithmVersion);
    }

    #[Test]
    public function with_used_index_is_idempotent_for_already_used(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['hash0', 'hash1'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        $result = $set->withUsedIndex(0);

        self::assertSame($set, $result);
    }

    #[Test]
    public function remaining_count_reflects_unused_codes(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['h0', 'h1', 'h2', 'h3', 'h4'],
            usedIndices: [0, 2],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        self::assertSame(3, $set->remainingCount());
    }

    #[Test]
    public function remaining_count_returns_zero_when_all_used(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['h0', 'h1'],
            usedIndices: [0, 1],
            algorithmVersion: 2,
            createdAt: 1700000000,
        );

        self::assertSame(0, $set->remainingCount());
    }

    #[Test]
    public function remaining_count_equals_total_when_none_used(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-1',
            codeHashes: ['h0', 'h1', 'h2'],
            usedIndices: [],
            algorithmVersion: 1,
            createdAt: 1700000000,
        );

        self::assertSame(3, $set->remainingCount());
    }
}
