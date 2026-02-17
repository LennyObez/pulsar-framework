<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Invoice lifecycle status.
 */
#[Api(since: '1.0.0')]
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Voided = 'voided';
    case Uncollectible = 'uncollectible';
}
