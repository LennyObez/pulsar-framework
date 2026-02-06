<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Api;

/**
 * Supported runtime drivers.
 */
#[Api(since: '1.0.0')]
enum RuntimeType: string
{
    case Fpm = 'fpm';
    case Persistent = 'persistent';
    case FrankenPhp = 'frankenphp';
    case RoadRunner = 'roadrunner';
}
