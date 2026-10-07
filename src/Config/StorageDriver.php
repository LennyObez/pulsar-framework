<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available storage driver types.
 * @api
 */
#[Api(since: '1.0.0')]
enum StorageDriver: string
{
    case Local = 'local';
    case S3 = 's3';
    case Memory = 'memory';
}
