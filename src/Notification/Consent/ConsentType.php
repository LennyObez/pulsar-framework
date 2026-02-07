<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Type of consent action recorded.
 */
#[Api(since: '1.0.0')]
enum ConsentType: string
{
    case OptIn = 'opt_in';
    case OptOut = 'opt_out';
}
