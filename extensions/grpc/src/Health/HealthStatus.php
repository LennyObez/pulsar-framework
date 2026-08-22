<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Health;

use Pulsar\Api\Api;

/**
 * Health status per the grpc.health.v1.Health specification.
 *
 * @see https://github.com/grpc/grpc/blob/master/doc/health-checking.md
 * @api
 */
#[Api(since: '1.0.0')]
enum HealthStatus: int
{
    case Unknown = 0;
    case Serving = 1;
    case NotServing = 2;
    case ServiceUnknown = 3;
}
