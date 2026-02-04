<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

/**
 * Available storage backends for feature flag definitions.
 */
enum FlagStorageDriver: string
{
    case Memory = 'memory';
    case File = 'file';
}
