<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Pulsar\Api\Api;

/**
 * Platform classification for application releases.
 */
#[Api(since: '1.0.0')]
enum ReleasePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';
}
