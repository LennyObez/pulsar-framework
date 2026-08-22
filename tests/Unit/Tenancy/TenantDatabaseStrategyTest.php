<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\TenantDatabaseStrategy;
use ValueError;

#[CoversNothing]
final class TenantDatabaseStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('strategyValuesProvider')]
    public function backedValuesAreCorrect(TenantDatabaseStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    /**
     * @return iterable<string, array{TenantDatabaseStrategy, string}>
     */
    public static function strategyValuesProvider(): iterable
    {
        yield 'Prefix' => [TenantDatabaseStrategy::Prefix, 'prefix'];
        yield 'SeparateConnection' => [TenantDatabaseStrategy::SeparateConnection, 'separate_connection'];
        yield 'Shared' => [TenantDatabaseStrategy::Shared, 'shared'];
    }

    #[Test]
    public function hasExactlyThreeCases(): void
    {
        self::assertCount(3, TenantDatabaseStrategy::cases());
    }

    #[Test]
    public function fromValidBackedValue(): void
    {
        self::assertSame(TenantDatabaseStrategy::Prefix, TenantDatabaseStrategy::from('prefix'));
        self::assertSame(TenantDatabaseStrategy::SeparateConnection, TenantDatabaseStrategy::from('separate_connection'));
        self::assertSame(TenantDatabaseStrategy::Shared, TenantDatabaseStrategy::from('shared'));
    }

    #[Test]
    public function fromInvalidBackedValueThrows(): void
    {
        $this->expectException(ValueError::class);
        TenantDatabaseStrategy::from('invalid');
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(TenantDatabaseStrategy::tryFrom('nonexistent'));
    }
}
