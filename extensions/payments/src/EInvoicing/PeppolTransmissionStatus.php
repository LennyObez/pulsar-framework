<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use Pulsar\Api\Api;

/**
 * Peppol transmission status.
 * @api
 */
#[Api(since: '1.0.0')]
enum PeppolTransmissionStatus: string
{
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
