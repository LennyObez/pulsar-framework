<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;

#[CoversNothing]
final class ShippingMethodTest extends TestCase
{
    #[Test]
    #[DataProvider('shippingMethodProvider')]
    public function fromValueResolves(string $value, ShippingMethod $expected): void
    {
        self::assertSame($expected, ShippingMethod::from($value));
    }

    /**
     * @return array<string, array{string, ShippingMethod}>
     */
    public static function shippingMethodProvider(): array
    {
        return [
            'standard' => ['standard', ShippingMethod::Standard],
            'express' => ['express', ShippingMethod::Express],
            'overnight' => ['overnight', ShippingMethod::Overnight],
            'digital' => ['digital', ShippingMethod::Digital],
        ];
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(ShippingMethod::tryFrom('teleportation'));
    }
}
