<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Charge status values.
 */
#[Api(since: '1.0.0')]
enum ChargeStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
