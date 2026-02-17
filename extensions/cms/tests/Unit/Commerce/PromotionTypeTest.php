<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\PromotionType;

#[CoversClass(PromotionType::class)]
final class PromotionTypeTest extends TestCase
{
    #[Test]
    public function label_returns_human_readable_names(): void
    {
        self::assertSame('Percentage Off', PromotionType::PercentageOff->label());
        self::assertSame('Fixed Amount Off', PromotionType::FixedAmountOff->label());
        self::assertSame('Free Shipping', PromotionType::FreeShipping->label());
        self::assertSame('Buy X Get Y', PromotionType::BuyXGetY->label());
    }

    #[Test]
    public function all_cases_have_string_values(): void
    {
        foreach (PromotionType::cases() as $case) {
            self::assertNotEmpty($case->value);
        }
    }
}
