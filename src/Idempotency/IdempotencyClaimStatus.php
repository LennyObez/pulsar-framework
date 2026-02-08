<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use Pulsar\Api\Api;

/**
 * Status of an idempotency claim.
 */
#[Api]
enum IdempotencyClaimStatus
{
    case Replay;
    case Claimed;
    case Mismatch;
}
