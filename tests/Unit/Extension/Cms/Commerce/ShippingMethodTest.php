<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;

#[CoversNothing]
final class ShippingMethodTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = ShippingMethod::cases();
        self::assertCount(4, $cases);
    }

    #[Test]
    #[DataProvider('shippingMethodProvider')]
    public function casesHaveCorrectStringValues(ShippingMethod $method, string $expectedValue): void
    {
        self::assertSame($expectedValue, $method->value);
    }

    /**
     * @return iterable<string, array{ShippingMethod, string}>
     */
    public static function shippingMethodProvider(): iterable
    {
        yield 'standard' => [ShippingMethod::Standard, 'standard'];
        yield 'express' => [ShippingMethod::Express, 'express'];
        yield 'overnight' => [ShippingMethod::Overnight, 'overnight'];
        yield 'digital' => [ShippingMethod::Digital, 'digital'];
    }

    #[Test]
    public function fromStringBackedValue(): void
    {
        self::assertSame(ShippingMethod::Express, ShippingMethod::from('express'));
        self::assertNull(ShippingMethod::tryFrom('carrier_pigeon'));
    }
}
