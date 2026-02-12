<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\BindingType;
use Pulsar\Container\Lifetime;

#[CoversClass(Lifetime::class)]
#[CoversClass(BindingType::class)]
final class LifetimeTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = Lifetime::cases();

        self::assertCount(4, $cases);
        self::assertSame('singleton', Lifetime::Singleton->value);
        self::assertSame('transient', Lifetime::Transient->value);
        self::assertSame('request', Lifetime::RequestScope->value);
        self::assertSame('tenant', Lifetime::TenantScope->value);
    }

    #[Test]
    public function bindingTypeSingletonConvertsToSingleton(): void
    {
        self::assertSame(Lifetime::Singleton, BindingType::Singleton->toLifetime());
    }

    #[Test]
    public function bindingTypeFactoryConvertsToTransient(): void
    {
        self::assertSame(Lifetime::Transient, BindingType::Factory->toLifetime());
    }
}
