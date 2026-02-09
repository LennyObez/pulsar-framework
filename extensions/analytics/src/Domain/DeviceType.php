<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * Device type classification derived from user agent analysis.
 */
#[Api(since: '1.0.0')]
enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Unknown = 'unknown';
}
