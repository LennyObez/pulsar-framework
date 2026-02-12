<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices;

use Pulsar\Api\Api;

/**
 * Supported device platforms.
 */
#[Api(since: '1.0.0')]
enum Platform: string
{
    case Android = 'android';
    case iOS = 'ios';
    case Web = 'web';
}
