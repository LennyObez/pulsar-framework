<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Pulsar\Api\Api;

/**
 * Identifies the app store platform that originated a subscription.
 * @api
 */
#[Api(since: '1.0.0')]
enum Store: string
{
    case Google = 'google';
    case Apple = 'apple';
}
