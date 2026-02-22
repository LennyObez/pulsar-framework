<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available application cache driver types.
 */
#[Api(since: '1.0.0')]
enum CacheDriverType: string
{
    case Array = 'array';
    case Filesystem = 'filesystem';
    case Database = 'database';
    case Redis = 'redis';
    case Memcached = 'memcached';
    case Apcu = 'apcu';
}
