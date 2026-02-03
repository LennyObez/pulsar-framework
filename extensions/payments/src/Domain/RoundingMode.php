<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Rounding mode for Money arithmetic.
 */
#[Api]
enum RoundingMode
{
    case HalfUp;
    case HalfDown;
    case HalfEven;
    case Floor;
    case Ceiling;
}
