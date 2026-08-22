<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Session\HijackPolicy;

#[CoversNothing]
final class HijackPolicyTest extends TestCase
{
    #[Test]
    public function hasThreeCases(): void
    {
        self::assertCount(3, HijackPolicy::cases());
    }

    #[Test]
    #[DataProvider('policyProvider')]
    public function backedValues(HijackPolicy $policy, string $expected): void
    {
        self::assertSame($expected, $policy->value);
    }

    /**
     * @return iterable<string, array{HijackPolicy, string}>
     */
    public static function policyProvider(): iterable
    {
        yield 'Invalidate' => [HijackPolicy::Invalidate, 'invalidate'];
        yield 'Challenge' => [HijackPolicy::Challenge, 'challenge'];
        yield 'Warn' => [HijackPolicy::Warn, 'warn'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (HijackPolicy::cases() as $policy) {
            self::assertSame($policy, HijackPolicy::from($policy->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(HijackPolicy::tryFrom('block'));
    }
}
