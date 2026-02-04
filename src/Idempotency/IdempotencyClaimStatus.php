<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use Pulsar\Api\Api;

/**
 * Status of an idempotency claim.
 */
#[Api(since: '1.0.0')]
enum IdempotencyClaimStatus
{
    case Replay;
    case Claimed;
    case Mismatch;
}
