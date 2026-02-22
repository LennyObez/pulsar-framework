<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Available shipping methods for order fulfillment.
 */
#[Api(since: '1.0.0')]
enum ShippingMethod: string
{
    case Standard = 'standard';
    case Express = 'express';
    case Overnight = 'overnight';
    case Digital = 'digital';
}
