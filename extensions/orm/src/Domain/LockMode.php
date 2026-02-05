<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Row-level lock modes for SELECT ... FOR UPDATE / SHARE.
 */
#[Api(since: '1.0.0')]
enum LockMode: string
{
    case None = 'none';
    case ForUpdate = 'for_update';
    case ForShare = 'for_share';
}
