<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Pulsar\Api\Api;

/**
 * Device type preference for beta signup participants.
 */
#[Api(since: '1.0.0')]
enum DeviceType: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Both = 'both';
}
