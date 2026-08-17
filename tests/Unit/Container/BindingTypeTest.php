<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\BindingType;
use Pulsar\Container\Lifetime;

#[CoversNothing]
final class BindingTypeTest extends TestCase
{
    #[Test]
    public function singletonHasCorrectValue(): void
    {
        self::assertSame('singleton', BindingType::Singleton->value);
    }

    #[Test]
    public function factoryHasCorrectValue(): void
    {
        self::assertSame('factory', BindingType::Factory->value);
    }

    #[Test]
    public function singletonConvertsToSingletonLifetime(): void
    {
        self::assertSame(Lifetime::Singleton, BindingType::Singleton->toLifetime());
    }

    #[Test]
    public function factoryConvertsToTransientLifetime(): void
    {
        self::assertSame(Lifetime::Transient, BindingType::Factory->toLifetime());
    }

    /**
     * @return iterable<string, array{BindingType, Lifetime}>
     */
    public static function lifetimeConversionProvider(): iterable
    {
        yield 'singleton' => [BindingType::Singleton, Lifetime::Singleton];
        yield 'factory' => [BindingType::Factory, Lifetime::Transient];
    }

    #[Test]
    #[DataProvider('lifetimeConversionProvider')]
    public function toLifetimeMapsCorrectly(BindingType $type, Lifetime $expected): void
    {
        self::assertSame($expected, $type->toLifetime());
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        self::assertSame(BindingType::Singleton, BindingType::from('singleton'));
        self::assertSame(BindingType::Factory, BindingType::from('factory'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(BindingType::tryFrom('invalid'));
    }

    #[Test]
    public function allCasesAccountedFor(): void
    {
        $cases = BindingType::cases();

        self::assertCount(2, $cases);
    }
}
