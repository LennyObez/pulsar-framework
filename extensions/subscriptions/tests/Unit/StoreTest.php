<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use ValueError;

#[CoversClass(Store::class)]
final class StoreTest extends TestCase
{
    #[Test]
    public function googleValueIsGoogle(): void
    {
        self::assertSame('google', Store::Google->value);
    }

    #[Test]
    public function appleValueIsApple(): void
    {
        self::assertSame('apple', Store::Apple->value);
    }

    #[Test]
    public function exactlyTwoCasesExist(): void
    {
        self::assertCount(2, Store::cases());
    }

    #[Test]
    public function fromStringReturnsCorrectCase(): void
    {
        self::assertSame(Store::Google, Store::from('google'));
        self::assertSame(Store::Apple, Store::from('apple'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownStore(): void
    {
        self::assertNull(Store::tryFrom('samsung'));
    }

    #[Test]
    public function fromThrowsForInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        Store::from('huawei');
    }
}
