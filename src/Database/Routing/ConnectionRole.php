<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Pulsar\Api\Api;

/**
 * Designates whether a connection is used for read or write operations.
 * @api
 */
#[Api(since: '1.0.0')]
enum ConnectionRole: string
{
    case Read = 'read';
    case Write = 'write';
}
