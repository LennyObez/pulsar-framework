<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\TwoFactorStatus;

#[CoversClass(TwoFactorStatus::class)]
final class TwoFactorStatusTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        self::assertCount(3, TwoFactorStatus::cases());
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function backedValues(TwoFactorStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{TwoFactorStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'Disabled' => [TwoFactorStatus::Disabled, 'disabled'];
        yield 'Pending' => [TwoFactorStatus::Pending, 'pending'];
        yield 'Verified' => [TwoFactorStatus::Verified, 'verified'];
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(TwoFactorStatus::Disabled, TwoFactorStatus::from('disabled'));
        self::assertSame(TwoFactorStatus::Verified, TwoFactorStatus::from('verified'));
    }
}
