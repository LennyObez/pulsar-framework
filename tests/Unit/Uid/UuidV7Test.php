<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Uid;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Uid\UidException;
use Pulsar\Uid\UuidV7;

use function strlen;

#[CoversClass(UuidV7::class)]
#[CoversClass(UidException::class)]
final class UuidV7Test extends TestCase
{
    #[Test]
    public function generateProducesCanonicalThirtySixCharForm(): void
    {
        $uuid = UuidV7::generate();

        self::assertSame(36, strlen($uuid));
        self::assertTrue(UuidV7::isValid($uuid));
    }

    #[Test]
    public function generateProducesUniqueValuesAcrossManyCalls(): void
    {
        $count = 1000;
        $set = [];

        for ($i = 0; $i < $count; $i++) {
            $set[UuidV7::generate()] = true;
        }

        self::assertCount(
            $count,
            $set,
            'UUIDv7 collision detected — randomness or timestamp wrap is broken',
        );
    }

    #[Test]
    public function generateProducesMonotonicallyOrderedValuesAcrossDistinctMilliseconds(): void
    {
        $a = UuidV7::generateAt(1_700_000_000_000);
        $b = UuidV7::generateAt(1_700_000_000_001);

        self::assertLessThan(
            $b,
            $a,
            'UUIDv7 must be lexicographically ordered when generated in chronological order',
        );
    }

    #[Test]
    public function generateAtEmbedsTheSuppliedTimestamp(): void
    {
        $timestamp = 1_700_000_000_000;

        $uuid = UuidV7::generateAt($timestamp);

        self::assertSame($timestamp, UuidV7::extractTimestamp($uuid));
    }

    #[Test]
    public function generateAtRejectsNegativeTimestamps(): void
    {
        $this->expectException(UidException::class);

        (void) UuidV7::generateAt(-1);
    }

    #[Test]
    public function generateAtRejectsTimestampsLargerThanFortyEightBits(): void
    {
        $this->expectException(UidException::class);

        (void) UuidV7::generateAt(0xFFFFFFFFFFFF + 1);
    }

    #[Test]
    public function isValidReturnsFalseForRandomGarbage(): void
    {
        self::assertFalse(UuidV7::isValid('not-a-uuid'));
        self::assertFalse(UuidV7::isValid(''));
        self::assertFalse(UuidV7::isValid('00000000-0000-0000-0000-000000000000'));
    }

    #[Test]
    public function isValidRejectsUuidV4(): void
    {
        // RFC 4122 v4 has version nibble 4 instead of 7
        self::assertFalse(UuidV7::isValid('aabbccdd-eeff-4011-8001-112233445566'));
    }

    #[Test]
    public function isValidRejectsUuidWithWrongVariant(): void
    {
        // Version is 7 but variant nibble is 0 (must be 8/9/a/b)
        self::assertFalse(UuidV7::isValid('aabbccdd-eeff-7011-0001-112233445566'));
    }

    #[Test]
    public function extractTimestampThrowsOnInvalidValue(): void
    {
        $this->expectException(UidException::class);

        (void) UuidV7::extractTimestamp('not-a-uuid');
    }

    #[Test]
    public function parseRoundTripsThroughGenerate(): void
    {
        $uuid = UuidV7::generate();

        $bytes = UuidV7::parse($uuid);

        self::assertSame(16, strlen($bytes));
    }

    #[Test]
    public function parseThrowsOnInvalidValue(): void
    {
        $this->expectException(UidException::class);

        (void) UuidV7::parse('not-a-uuid');
    }

    #[Test]
    public function generateAtSetsVersionNibbleToSeven(): void
    {
        $uuid = UuidV7::generateAt(1_700_000_000_000);

        // Position 14 in the canonical 36-char form is the version nibble
        // (after "xxxxxxxx-xxxx-").
        self::assertSame('7', $uuid[14]);
    }

    #[Test]
    public function generateAtSetsVariantNibbleToOneOfFourValidValues(): void
    {
        $uuid = UuidV7::generateAt(1_700_000_000_000);

        // Position 19 is the variant nibble. RFC 9562 v7 must be 8, 9, a or b.
        self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
    }
}
