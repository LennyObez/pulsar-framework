<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Support\UuidGenerator;

use function array_unique;
use function explode;
use function strlen;

#[CoversClass(UuidGenerator::class)]
final class UuidGeneratorTest extends TestCase
{
    #[Test]
    public function v7ReturnsStringWithFiveDashSeparatedHexSegments(): void
    {
        $uuid = UuidGenerator::v7();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]+-[0-9a-f]+-7[0-9a-f]+-[89ab][0-9a-f]+-[0-9a-f]+$/',
            $uuid,
        );
    }

    #[Test]
    public function v7ReturnsVersion7Marker(): void
    {
        $uuid = UuidGenerator::v7();

        // Version nibble is character at position 14 (index after two dashes)
        self::assertSame('7', $uuid[14]);
    }

    #[Test]
    public function v7ReturnsVariant1Marker(): void
    {
        $uuid = UuidGenerator::v7();
        $variantChar = $uuid[19];

        self::assertContains($variantChar, ['8', '9', 'a', 'b']);
    }

    #[Test]
    public function v7GeneratesUniqueValues(): void
    {
        $uuids = [];
        for ($i = 0; $i < 50; $i++) {
            $uuids[] = UuidGenerator::v7();
        }

        self::assertCount(50, array_unique($uuids), 'Expected 50 unique UUIDs');
    }

    #[Test]
    public function v7ContainsFiveDashSeparatedSegments(): void
    {
        $uuid = UuidGenerator::v7();
        $segments = explode('-', $uuid);

        self::assertCount(5, $segments);
    }

    #[Test]
    public function v7FirstSegmentIsEightHexChars(): void
    {
        $uuid = UuidGenerator::v7();
        $segments = explode('-', $uuid);

        self::assertSame(8, strlen($segments[0]));
    }

    #[Test]
    public function v7SecondSegmentIsFourHexChars(): void
    {
        $uuid = UuidGenerator::v7();
        $segments = explode('-', $uuid);

        self::assertSame(4, strlen($segments[1]));
    }

    #[Test]
    public function v7ThirdSegmentStartsWithSeven(): void
    {
        $uuid = UuidGenerator::v7();
        $segments = explode('-', $uuid);

        self::assertStringStartsWith('7', $segments[2]);
    }

    #[Test]
    public function v7FourthSegmentStartsWithVariantBit(): void
    {
        $uuid = UuidGenerator::v7();
        $segments = explode('-', $uuid);

        self::assertContains($segments[3][0], ['8', '9', 'a', 'b']);
    }

    #[Test]
    public function v7ContainsOnlyHexCharsAndDashes(): void
    {
        $uuid = UuidGenerator::v7();

        self::assertMatchesRegularExpression('/^[0-9a-f-]+$/', $uuid);
    }
}
