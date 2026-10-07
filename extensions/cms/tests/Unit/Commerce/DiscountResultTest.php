<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\DiscountResult;

#[CoversClass(DiscountResult::class)]
final class DiscountResultTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $result = new DiscountResult(
            totalDiscount: 500,
            itemDiscounts: ['p-1' => 300, 'p-2' => 200],
        );

        self::assertSame(500, $result->totalDiscount);
        self::assertSame(300, $result->itemDiscounts['p-1']);
        self::assertSame(200, $result->itemDiscounts['p-2']);
    }

    #[Test]
    public function zeroDiscountResult(): void
    {
        $result = new DiscountResult(
            totalDiscount: 0,
            itemDiscounts: [],
        );

        self::assertSame(0, $result->totalDiscount);
        self::assertSame([], $result->itemDiscounts);
    }
}
