<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Domain;

use Pulsar\Api\Api;

/**
 * Status of a WebRTC call session.
 * @api
 */
#[Api(since: '1.0.0')]
enum CallStatus: string
{
    case Pending = 'pending';
    case Ringing = 'ringing';
    case Active = 'active';
    case Ended = 'ended';
    case Rejected = 'rejected';
    case Missed = 'missed';
}
