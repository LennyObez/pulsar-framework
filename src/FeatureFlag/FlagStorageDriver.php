<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Pulsar\Api\Api;

/**
 * Available storage backends for feature flag definitions.
 */
#[Api]
enum FlagStorageDriver: string
{
    case Memory = 'memory';
    case File = 'file';
}
