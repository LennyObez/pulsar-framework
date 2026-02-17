<?php

declare(strict_types=1);

namespace Pulsar\Mail\Audit;

use Pulsar\Api\Api;

/**
 * Delivery status of an audited mail message.
 */
#[Api(since: '1.0.0')]
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';
}
