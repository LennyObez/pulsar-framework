<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\VoteDirection;

#[CoversNothing]
final class VoteDirectionTest extends TestCase
{
    #[Test]
    public function upHasValueOne(): void
    {
        self::assertSame(1, VoteDirection::Up->value);
    }

    #[Test]
    public function downHasValueNegativeOne(): void
    {
        self::assertSame(-1, VoteDirection::Down->value);
    }

    #[Test]
    public function casesContainsExactlyTwoMembers(): void
    {
        self::assertCount(2, VoteDirection::cases());
    }

    #[Test]
    #[DataProvider('backingValueProvider')]
    public function fromBackingValueRoundTrips(int $value, VoteDirection $expected): void
    {
        self::assertSame($expected, VoteDirection::from($value));
    }

    /** @return iterable<string, array{int, VoteDirection}> */
    public static function backingValueProvider(): iterable
    {
        yield 'up' => [1, VoteDirection::Up];
        yield 'down' => [-1, VoteDirection::Down];
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(VoteDirection::tryFrom(0));
        self::assertNull(VoteDirection::tryFrom(2));
    }

    #[Test]
    public function valuesAreSuitableForScoreAggregation(): void
    {
        $score = VoteDirection::Up->value + VoteDirection::Up->value + VoteDirection::Down->value;
        self::assertSame(1, $score);
    }
}
