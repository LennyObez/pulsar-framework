<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Security;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Security\TakeoverRiskLevel;

#[CoversNothing]
final class TakeoverRiskLevelTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        self::assertCount(3, TakeoverRiskLevel::cases());
    }

    #[Test]
    #[DataProvider('levelProvider')]
    public function backedValues(TakeoverRiskLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }

    /**
     * @return iterable<string, array{TakeoverRiskLevel, string}>
     */
    public static function levelProvider(): iterable
    {
        yield 'Low' => [TakeoverRiskLevel::Low, 'low'];
        yield 'Elevated' => [TakeoverRiskLevel::Elevated, 'elevated'];
        yield 'High' => [TakeoverRiskLevel::High, 'high'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (TakeoverRiskLevel::cases() as $level) {
            self::assertSame($level, TakeoverRiskLevel::from($level->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(TakeoverRiskLevel::tryFrom('critical'));
    }
}
