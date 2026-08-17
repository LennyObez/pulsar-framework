<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tenancy\TenantResolverStrategy;
use ValueError;

#[CoversNothing]
final class TenantResolverStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('strategyValuesProvider')]
    public function backedValuesAreCorrect(TenantResolverStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    /**
     * @return iterable<string, array{TenantResolverStrategy, string}>
     */
    public static function strategyValuesProvider(): iterable
    {
        yield 'Header' => [TenantResolverStrategy::Header, 'header'];
        yield 'Subdomain' => [TenantResolverStrategy::Subdomain, 'subdomain'];
        yield 'Path' => [TenantResolverStrategy::Path, 'path'];
    }

    #[Test]
    public function hasExactlyThreeCases(): void
    {
        self::assertCount(3, TenantResolverStrategy::cases());
    }

    #[Test]
    public function fromValidBackedValue(): void
    {
        self::assertSame(TenantResolverStrategy::Header, TenantResolverStrategy::from('header'));
        self::assertSame(TenantResolverStrategy::Subdomain, TenantResolverStrategy::from('subdomain'));
        self::assertSame(TenantResolverStrategy::Path, TenantResolverStrategy::from('path'));
    }

    #[Test]
    public function fromInvalidBackedValueThrows(): void
    {
        $this->expectException(ValueError::class);
        TenantResolverStrategy::from('cookie');
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(TenantResolverStrategy::tryFrom('nonexistent'));
    }
}
