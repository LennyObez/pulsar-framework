<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeSet;

use function count;

#[CoversClass(RecoveryCodeGenerator::class)]
#[CoversClass(RecoveryCodeSet::class)]
final class RecoveryCodeGeneratorTest extends TestCase
{
    private RecoveryCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RecoveryCodeGenerator();
    }

    #[Test]
    public function generateReturnsCorrectCountOfCodes(): void
    {
        $codes = $this->generator->generate(8);

        self::assertCount(8, $codes);

        $codesFive = $this->generator->generate(5);

        self::assertCount(5, $codesFive);
    }

    #[Test]
    public function eachCodeMatchesExpectedPattern(): void
    {
        $codes = $this->generator->generate(8);

        foreach ($codes as $code) {
            // 64-bit format: XXXX-XXXX-XXXX-XXXX (16 hex chars)
            self::assertMatchesRegularExpression(
                '/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/',
                $code,
            );
        }
    }

    #[Test]
    public function legacyGeneratorProduces32BitFormat(): void
    {
        $legacyGenerator = new RecoveryCodeGenerator(bytesPerCode: 4);
        $codes = $legacyGenerator->generate(4);

        foreach ($codes as $code) {
            // 32-bit format: XXXX-XXXX (8 hex chars)
            self::assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        }
    }

    #[Test]
    public function generatedCodesAreUnique(): void
    {
        $codes = $this->generator->generate(16);

        self::assertSame(count($codes), count(array_unique($codes)));
    }

    #[Test]
    public function checksumProducesConsistentResult(): void
    {
        $code = 'ABCD-1234-EF56-7890';
        $checksum1 = RecoveryCodeGenerator::checksum($code);
        $checksum2 = RecoveryCodeGenerator::checksum($code);

        self::assertSame($checksum1, $checksum2);
        self::assertMatchesRegularExpression('/^[0-9A-F]{2}$/', $checksum1);
    }

    #[Test]
    public function canonicalizeStripsDelimitersAndUppercases(): void
    {
        self::assertSame('ABCD1234EF567890', RecoveryCodeGenerator::canonicalize('abcd-1234-ef56-7890'));
        self::assertSame('ABCD1234EF567890', RecoveryCodeGenerator::canonicalize('ABCD 1234 EF56 7890'));
    }

    #[Test]
    public function checksumReturns00ForInvalidHex(): void
    {
        // hex2bin() emits E_WARNING for non-hex input; suppress so test stays clean
        $previous = set_error_handler(static fn(): bool => true);

        try {
            self::assertSame('00', RecoveryCodeGenerator::checksum('ZZZZ-ZZZZ'));
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function checksumProducesDifferentValuesForDifferentCodes(): void
    {
        $checksum1 = RecoveryCodeGenerator::checksum('ABCD-1234-EF56-7890');
        $checksum2 = RecoveryCodeGenerator::checksum('1234-5678-9ABC-DEF0');

        self::assertNotSame($checksum1, $checksum2);
    }

    #[Test]
    public function withUsedIndexReturnsNewSetWithMarkedIndex(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-001',
            codeHashes: ['hash-0', 'hash-1', 'hash-2', 'hash-3'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1709800000,
        );

        $updated = $set->withUsedIndex(1);

        self::assertNotSame($set, $updated);
        self::assertFalse($set->isUsed(1));
        self::assertTrue($updated->isUsed(1));
        self::assertSame([1], $updated->usedIndices);
    }

    #[Test]
    public function withUsedIndexReturnsSameInstanceIfAlreadyUsed(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-002',
            codeHashes: ['hash-0', 'hash-1'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1709800000,
        );

        $result = $set->withUsedIndex(0);

        self::assertSame($set, $result);
    }

    #[Test]
    public function remainingCountReflectsUsedIndices(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-003',
            codeHashes: ['hash-0', 'hash-1', 'hash-2', 'hash-3', 'hash-4'],
            usedIndices: [],
            algorithmVersion: 2,
            createdAt: 1709800000,
        );

        self::assertSame(5, $set->remainingCount());

        $used1 = $set->withUsedIndex(0);
        self::assertSame(4, $used1->remainingCount());

        $used2 = $used1->withUsedIndex(3);
        self::assertSame(3, $used2->remainingCount());
    }

    #[Test]
    public function isUsedReturnsFalseForUnusedIndex(): void
    {
        $set = new RecoveryCodeSet(
            setId: 'set-004',
            codeHashes: ['hash-0', 'hash-1'],
            usedIndices: [0],
            algorithmVersion: 2,
            createdAt: 1709800000,
        );

        self::assertTrue($set->isUsed(0));
        self::assertFalse($set->isUsed(1));
    }
}
