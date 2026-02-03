<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Available storage driver types.
 */
#[Api]
enum StorageDriver: string
{
    case Local = 'local';
    case S3 = 's3';
    case Memory = 'memory';
}
