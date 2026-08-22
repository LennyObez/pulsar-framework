<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Vote direction with integer value for score aggregation.
 * @api
 */
#[Api(since: '1.0.0')]
enum VoteDirection: int
{
    case Up = 1;
    case Down = -1;
}
