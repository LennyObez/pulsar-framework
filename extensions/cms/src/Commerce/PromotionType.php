<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Types of promotional discounts that can be applied to orders.
 * @api
 */
#[Api(since: '1.0.0')]
enum PromotionType: string
{
    case PercentageOff = 'percentage_off';
    case FixedAmountOff = 'fixed_amount_off';
    case FreeShipping = 'free_shipping';
    case BuyXGetY = 'buy_x_get_y';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::PercentageOff => 'Percentage Off',
            self::FixedAmountOff => 'Fixed Amount Off',
            self::FreeShipping => 'Free Shipping',
            self::BuyXGetY => 'Buy X Get Y',
        };
    }
}
