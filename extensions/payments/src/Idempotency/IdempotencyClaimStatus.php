<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Idempotency;

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
