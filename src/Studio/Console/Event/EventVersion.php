<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event;

use Pulsar\Api\Internal;

/**
 * Event schema version.
 */
#[Internal]
enum EventVersion: int
{
    case V1 = 1;
}
