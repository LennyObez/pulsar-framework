<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Mobile app store platforms for in-app purchases.
 * @api
 */
#[Api(since: '1.0.0')]
enum MobileStore: string
{
    case Apple = 'apple';
    case Google = 'google';
}
