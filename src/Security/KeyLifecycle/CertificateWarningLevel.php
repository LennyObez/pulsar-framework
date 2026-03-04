<?php

declare(strict_types=1);

namespace Pulsar\Security\KeyLifecycle;

use Pulsar\Api\Api;

/**
 * Warning level for certificate expiration alerts.
 */
#[Api(since: '1.0.0')]
enum CertificateWarningLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
    case Expired = 'expired';
}
