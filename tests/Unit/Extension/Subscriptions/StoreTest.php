<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use ValueError;

final class StoreTest extends TestCase
{
    #[Test]
    public function caseCount(): void
    {
        self::assertCount(2, Store::cases());
    }

    #[Test]
    public function googleValue(): void
    {
        self::assertSame('google', Store::Google->value);
    }

    #[Test]
    public function appleValue(): void
    {
        self::assertSame('apple', Store::Apple->value);
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(Store::Google, Store::from('google'));
        self::assertSame(Store::Apple, Store::from('apple'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        self::assertNull(Store::tryFrom('windows'));
    }

    #[Test]
    public function fromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        Store::from('windows');
    }
}
