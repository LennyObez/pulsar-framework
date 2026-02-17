<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * Lifecycle status of a medical device.
 */
#[Api(since: '1.0.0')]
enum DeviceStatus: string
{
    case Active = 'active';
    case Recalled = 'recalled';
    case Suspended = 'suspended';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
